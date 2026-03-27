<?php

namespace App\Telegram;

use App\Service\BotGuard;
use App\Service\OpenAiImageService;
use App\Service\StickerService;
use App\Service\TelegramMessenger;
use App\Telegram\Dto\ParsedInput;
use BoShurik\TelegramBotBundle\Telegram\Command\AbstractCommand;
use BoShurik\TelegramBotBundle\Telegram\Command\PublicCommandInterface;
use Psr\Log\LoggerInterface;
use TelegramBot\Api\BotApi;
use TelegramBot\Api\Types\Update;

class StickerCommand extends AbstractCommand implements PublicCommandInterface
{
    private const string DEFAULT_EMOJI = "\u{1F3A8}";

    public function __construct(
        private readonly OpenAiImageService $openAiImageService,
        private readonly StickerService $stickerService,
        private readonly BotGuard $guard,
        private readonly TelegramMessenger $messenger,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return '/sticker';
    }

    public function getDescription(): string
    {
        return 'Generate a sticker';
    }

    public function isApplicable(Update $update): bool
    {
        $text = $update->getMessage()?->getText();

        if ($text === null) {
            return false;
        }

        return (bool) preg_match('/^\/sticker(@\w+)?(\s|$)/', $text);
    }

    public function execute(BotApi $api, Update $update): void
    {
        $message = $update->getMessage();
        $text = trim(preg_replace('/^\/sticker(@\w+)?\s*/', '', $message->getText()));

        if ($text === 'styles' || $text === '--help') {
            $this->messenger->reply($message, "Available styles:\n\n" . ParsedInput::styleList() . "\n\nUsage: /sticker 🐱 happy cat --pixel");
            return;
        }

        $input = ParsedInput::fromText($text, self::DEFAULT_EMOJI);

        if (empty($input->description)) {
            $this->messenger->reply($message, "Please provide a description.\n\nExample: /sticker 🐱 happy orange cat\nWith style: /sticker 🐱 happy cat --pixel\n\nType /sticker styles to see all styles.");
            return;
        }

        try {
            $user = $this->guard->resolveUser($message);
            if ($user === null) return;

            if (!$this->guard->checkDailyLimit($message, $user)) return;

            $pack = $this->guard->resolvePack($message, $user);
            if ($pack === null) return;

            $styleName = $input->style !== 'default' ? ' (' . OpenAiImageService::STYLES[$input->style]['name'] . ')' : '';
            $this->messenger->reply($message, "Generating your sticker$styleName...");

            $imageData = $this->openAiImageService->generateImage($input->description, $input->style);
            $pngData = $this->stickerService->convertToPng($imageData);
            $sticker = $this->stickerService->addSticker($pack, $pngData, $input->emoji, $input->description);

            $this->messenger->replySticker($message, $sticker->getFileId());
        } catch (\Throwable $e) {
            $this->logger->error('Failed to generate sticker', [
                'error' => $e->getMessage(),
                'exception' => $e,
                'chat_id' => $message->getChat()->getId(),
                'text' => $text,
            ]);

            $this->messenger->reply($message, "Something went wrong: {$e->getMessage()}");
        }
    }
}