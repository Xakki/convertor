<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Worker\WorkerCapabilityGcService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ручной синхронный проход long-TTL GC worker_capabilities.
 *
 * Если option опущена, команда использует env TTL сервиса; явное положительное
 * значение переопределяет TTL только для этого запуска.
 */
#[AsCommand(
    name: 'app:worker-capability:gc',
    description: 'Синхронно удалить устаревшие строки worker_capabilities с указанным TTL',
)]
final class WorkerCapabilityGcCommand extends Command
{
    public function __construct(
        private readonly WorkerCapabilityGcService $gc,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'ttl-hours',
            null,
            InputOption::VALUE_REQUIRED,
            'Положительный TTL в часах',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io          = new SymfonyStyle($input, $output);
        $ttlOverride = $input->getOption('ttl-hours');
        if ($ttlOverride === null) {
            $result = $this->gc->run();
            $io->success(sprintf('Удалено строк: %d (TTL из окружения).', $result['deleted']));

            return Command::SUCCESS;
        }

        $ttlHours = filter_var($ttlOverride, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($ttlHours === false) {
            $io->error('--ttl-hours должен быть положительным целым числом.');

            return Command::FAILURE;
        }

        $result = $this->gc->run($ttlHours);
        $io->success(sprintf('Удалено строк: %d (TTL: %d ч).', $result['deleted'], $ttlHours));

        return Command::SUCCESS;
    }
}
