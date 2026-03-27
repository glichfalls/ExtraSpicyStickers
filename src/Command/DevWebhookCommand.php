<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;
use TelegramBot\Api\BotApi;

#[AsCommand(
    name: 'app:dev:webhook',
    description: 'Start ngrok and register the Telegram webhook for local development',
)]
class DevWebhookCommand extends Command
{
    public function __construct(
        private readonly BotApi $botApi,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'Local port to expose', '8000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $port = $input->getOption('port');

        $io->info("Starting ngrok on port $port...");

        $ngrok = new Process(['ngrok', 'http', $port]);
        $ngrok->setTimeout(null);
        $ngrok->start();

        if (!$ngrok->isRunning()) {
            $io->error('Failed to start ngrok. Is it installed?');

            return Command::FAILURE;
        }

        $publicUrl = $this->waitForNgrokUrl();

        if (null === $publicUrl) {
            $io->error('Timed out waiting for ngrok tunnel.');
            $ngrok->stop();

            return Command::FAILURE;
        }

        $io->info("ngrok tunnel: $publicUrl");

        $webhookUrl = $publicUrl.'/webhook/';
        $this->botApi->setWebhook($webhookUrl);

        $io->success("Webhook set to: $webhookUrl");
        $io->note('ngrok is running. Press Ctrl+C to stop.');

        $ngrok->wait();

        return Command::SUCCESS;
    }

    private function waitForNgrokUrl(): ?string
    {
        $deadline = time() + 10;

        while (time() < $deadline) {
            usleep(300_000);

            $response = @file_get_contents('http://127.0.0.1:4040/api/tunnels');
            if (false === $response) {
                continue;
            }

            $tunnels = json_decode($response, true);
            foreach ($tunnels['tunnels'] ?? [] as $tunnel) {
                if (str_starts_with($tunnel['public_url'] ?? '', 'https://')) {
                    return $tunnel['public_url'];
                }
            }
        }

        return null;
    }
}
