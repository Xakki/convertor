<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Storage\S3Storage;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Очищает накопленные dev-данные в ОСНОВНОЙ БД `convertor` (ручное использование
 * dev-стенда), НЕ трогая тестовые БД `convertor-test`/`convertor_test`
 * (у них своя изоляция — см. skill `e2e-ws-transport-stack`).
 *
 * WIPE (полностью): `conversions`, `payments`, `social_identities`, `file_storage`
 * + связанные S3-объекты (по `file_storage.storage_path`) + `users` с
 * `is_admin=0` (гости и обычные юзеры).
 * PRESERVE: `users` с `is_admin=1` (админ), `plans`, `conversion_toggles`,
 * `doctrine_migration_versions` (миграции — трогать НЕЛЬЗЯ никогда),
 * `worker_capabilities` (операционный heartbeat, сам себя пересоберёт),
 * `examples` (карточка admin-managed-examples: untracked-S3-копии живут
 * НЕЗАВИСИМО от conversions — `examples.conversion_id` объявлен
 * `ON DELETE SET NULL`, так что безусловный `DELETE FROM conversions` ниже НЕ
 * трогает саму строку Example, только обнуляет необязательную ссылку).
 *
 * FK-граф (все RESTRICT, кроме social_identities→users CASCADE):
 *   conversions → users, file_storage(input_file_id/output_file_id)
 *   payments    → users
 *   social_identities → users (ON DELETE CASCADE)
 * MariaDB не разрешает TRUNCATE таблицы, на которую ссылается FK, даже если
 * ссылающихся строк уже нет — поэтому удаляем DELETE child-first в строгом
 * порядке: conversions → payments → social_identities → file_storage →
 * users(is_admin=0). Админ переживает даже собственные conversions/payments —
 * они тоже входят в общий вайп (согласованная семантика: остаётся только
 * сама users-строка админа).
 *
 * Квота (пер-тир daily/monthly счётчики в колонках `users`) живёт в `users` и
 * вайпается вместе с не-админами автоматически; отдельного шага сброса в KeyDB
 * НЕТ и не нужно (KeyDB-очереди/сессии эта команда не трогает вообще).
 */
#[AsCommand(
    name: 'app:clean-test-data',
    description: 'Очистить накопленные dev-данные (conversions/payments/social_identities/file_storage + не-админ users) в основной БД convertor',
)]
final class CleanTestDataCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly S3Storage $s3,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.environment%')]
        private readonly string $appEnv,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Выполнить без интерактивного подтверждения (обязателен в non-interactive режиме)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Только показать план — ничего не удалять (ни БД, ни S3)')
            ->addOption('keep-s3', null, InputOption::VALUE_NONE, 'Не трогать S3 — очистить только БД');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Hard-abort: команда никогда не должна выполняться на prod-инстансе,
        // даже с --force. kernel.environment — 'dev'/'test'/'prod' в Symfony 7.
        if (in_array($this->appEnv, ['prod', 'production'], true)) {
            $io->error(sprintf('APP_ENV=%s: очистка тестовых данных на prod ЗАПРЕЩЕНА.', $this->appEnv));

            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $keepS3 = (bool) $input->getOption('keep-s3');

        $connection = $this->em->getConnection();
        $counts     = $this->collectCounts($connection);

        $io->section('План очистки (main DB `convertor`)');
        $io->table(['Таблица', 'Строк к удалению'], [
            ['conversions', (string) $counts['conversions']],
            ['payments', (string) $counts['payments']],
            ['social_identities', (string) $counts['social_identities']],
            ['file_storage (+ S3-объекты)', (string) $counts['file_storage'] . ($keepS3 ? ' (S3 пропускается: --keep-s3)' : '')],
            ['users (is_admin=0)', (string) $counts['users_non_admin']],
        ]);
        $io->text(sprintf(
            'Сохраняются: users(is_admin=1)=%d, plans, conversion_toggles, doctrine_migration_versions, worker_capabilities.',
            $counts['users_admin'],
        ));

        if ($dryRun) {
            $io->note('--dry-run: ничего не удалено.');

            return Command::SUCCESS;
        }

        if (! $this->confirmed($input, $io)) {
            $io->warning('Отменено.');

            return Command::FAILURE;
        }

        // (a) Собираем storage_path ДО удаления строк file_storage — иначе ключи
        // потеряются вместе со строками.
        /** @var list<string> $storagePaths */
        $storagePaths = $connection->executeQuery('SELECT storage_path FROM file_storage')->fetchFirstColumn();

        $s3Deleted = 0;
        $s3Missing = 0;
        if (! $keepS3) {
            [$s3Deleted, $s3Missing] = $this->purgeS3($storagePaths, $io);
        }

        // (c) DB-вайп child-first одной транзакцией (см. FK-граф в докблоке класса).
        /** @var array{conversions: int, payments: int, social_identities: int, file_storage: int, users_non_admin: int} $deleted */
        $deleted = $this->em->wrapInTransaction(function () use ($connection): array {
            return [
                'conversions'       => $connection->executeStatement('DELETE FROM conversions'),
                'payments'          => $connection->executeStatement('DELETE FROM payments'),
                'social_identities' => $connection->executeStatement('DELETE FROM social_identities'),
                'file_storage'      => $connection->executeStatement('DELETE FROM file_storage'),
                'users_non_admin'   => $connection->executeStatement('DELETE FROM users WHERE is_admin = 0'),
            ];
        });

        $io->section('Итог очистки');
        $io->table(['Таблица', 'Удалено строк'], [
            ['conversions', (string) $deleted['conversions']],
            ['payments', (string) $deleted['payments']],
            ['social_identities', (string) $deleted['social_identities']],
            ['file_storage', (string) $deleted['file_storage']],
            ['users (non-admin)', (string) $deleted['users_non_admin']],
        ]);
        $io->text(sprintf(
            'S3: удалено %d, пропущено/не удалось %d%s.',
            $s3Deleted,
            $s3Missing,
            $keepS3 ? ' (--keep-s3: S3 не трогали)' : '',
        ));
        $io->success(sprintf('Очистка завершена. Сохранены: %d admin-пользователь(ей), plans, conversion_toggles, doctrine_migration_versions, worker_capabilities.', $counts['users_admin']));

        return Command::SUCCESS;
    }

    /**
     * @return array{conversions: int, payments: int, social_identities: int, file_storage: int, users_admin: int, users_non_admin: int}
     */
    private function collectCounts(Connection $connection): array
    {
        return [
            'conversions'       => (int) $connection->fetchOne('SELECT COUNT(*) FROM conversions'),
            'payments'          => (int) $connection->fetchOne('SELECT COUNT(*) FROM payments'),
            'social_identities' => (int) $connection->fetchOne('SELECT COUNT(*) FROM social_identities'),
            'file_storage'      => (int) $connection->fetchOne('SELECT COUNT(*) FROM file_storage'),
            'users_admin'       => (int) $connection->fetchOne('SELECT COUNT(*) FROM users WHERE is_admin = 1'),
            'users_non_admin'   => (int) $connection->fetchOne('SELECT COUNT(*) FROM users WHERE is_admin = 0'),
        ];
    }

    /**
     * --force → без подтверждения (нужен для non-interactive/CI). Иначе, на TTY —
     * интерактивный confirm; без TTY и без --force — hard abort (никогда не
     * удаляем молча).
     */
    private function confirmed(InputInterface $input, SymfonyStyle $io): bool
    {
        if ($input->getOption('force')) {
            return true;
        }

        if (! $input->isInteractive()) {
            $io->error('Non-interactive режим без --force: очистка отменена. Передайте --force для запуска без подтверждения.');

            return false;
        }

        return $io->confirm(
            'Удалить ВСЕ conversions/payments/social_identities/file_storage и всех non-admin users из БД convertor?',
            false,
        );
    }

    /**
     * Удаляет S3-объекты по собранным `storage_path`. Устойчиво к сбоям (сеть,
     * уже отсутствующий объект) — любая ошибка логируется и НЕ прерывает прогон
     * (тот же принцип, что и в {@see \App\Service\Storage\FileCleanupService}).
     *
     * @param list<string> $storagePaths
     *
     * @return array{0: int, 1: int} [удалено, пропущено/не удалось]
     */
    private function purgeS3(array $storagePaths, SymfonyStyle $io): array
    {
        $deleted = 0;
        $missing = 0;

        foreach ($storagePaths as $path) {
            $bucket = $this->resolveBucket($path);
            if ($bucket === null) {
                $io->warning("Неизвестный префикс storage_path, S3-объект пропущен: {$path}");
                $missing++;

                continue;
            }

            try {
                $this->s3->deleteObject($bucket, $path);
                $deleted++;
            } catch (\Throwable $e) {
                $this->logger->warning('app:clean-test-data: не удалось удалить S3-объект', [
                    'bucket' => $bucket,
                    'key'    => $path,
                    'error'  => $e->getMessage(),
                ]);
                $missing++;
            }
        }

        return [$deleted, $missing];
    }

    /**
     * Роутинг бакета по префиксу ключа (`inputs/…` / `results/…` — см.
     * {@see \App\Service\Conversion\ConversionManager} и
     * {@see \App\Service\Queue\ConversionResultPersister}). Выборка идёт ТОЛЬКО
     * по `file_storage.storage_path` — стабильные примеры лендинга (`examples/…`,
     * копия результата мимо FileStorage-строки, см. класс-докблок
     * {@see SeedExamplesCommand}) в этот список структурно попасть не могут,
     * поэтому переживают очистку без специальной логики-исключения.
     */
    private function resolveBucket(string $storagePath): ?string
    {
        return match (true) {
            str_starts_with($storagePath, 'inputs/')  => $this->s3->inputsBucket(),
            str_starts_with($storagePath, 'results/') => $this->s3->resultsBucket(),
            default                                   => null,
        };
    }
}
