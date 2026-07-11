<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Выдаёт указанному пользователю флаг админа (isAdmin=true → ROLE_ADMIN).
 *
 * Так назначается первый админ (UI-управления ролями нет). Аргумент —
 * e-mail или числовой id. Зовётся docker-only паттерном:
 *   make console CMD="app:user:make-admin admin@example.com"
 */
#[AsCommand(name: 'app:user:make-admin', description: 'Выдать пользователю права админа (ROLE_ADMIN)')]
final class MakeAdminCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'user',
            InputArgument::REQUIRED,
            'E-mail или числовой id пользователя',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $ref = trim((string) $input->getArgument('user'));

        if ($ref === '') {
            $io->error('Укажите e-mail или id пользователя.');

            return Command::FAILURE;
        }

        $user = $this->resolveUser($ref);

        if ($user === null) {
            $io->error("Пользователь не найден: {$ref}");

            return Command::FAILURE;
        }

        if ($user->isAdmin()) {
            $io->success("Пользователь #{$user->getId()} уже админ.");

            return Command::SUCCESS;
        }

        $user->setIsAdmin(true);
        $this->users->save($user, true);

        $io->success("Пользователю #{$user->getId()} выданы права админа (ROLE_ADMIN).");

        return Command::SUCCESS;
    }

    private function resolveUser(string $ref): ?User
    {
        // Чисто числовое значение трактуем как id, иначе — как e-mail.
        if (ctype_digit($ref)) {
            return $this->users->find((int) $ref);
        }

        return $this->users->findOneBy(['email' => $ref]);
    }
}
