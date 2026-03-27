<?php

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:make-admin',
    description: 'Promote a user to admin and set their password',
)]
class MakeAdminCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('username', InputArgument::REQUIRED, 'Telegram username (without @)');
        $this->addArgument('password', InputArgument::REQUIRED, 'Login password');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $username = ltrim($input->getArgument('username'), '@');
        $password = $input->getArgument('password');

        $user = $this->userRepository->findOneBy(['username' => $username]);

        if (!$user) {
            $io->error(sprintf('No user found with username "%s". The user must have used the bot at least once.', $username));

            return Command::FAILURE;
        }

        $user->setIsAdmin(true);
        $user->setPassword(password_hash($password, PASSWORD_BCRYPT));
        $this->entityManager->flush();

        $io->success(sprintf('User @%s is now an admin. They can log in at /admin/login', $username));

        return Command::SUCCESS;
    }
}
