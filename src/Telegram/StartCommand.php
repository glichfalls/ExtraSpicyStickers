<?php

namespace App\Telegram;

use BoShurik\TelegramBotBundle\Telegram\Command\AbstractCommand;
use BoShurik\TelegramBotBundle\Telegram\Command\PublicCommandInterface;
use TelegramBot\Api\BotApi;
use TelegramBot\Api\Types\Update;

class StartCommand extends AbstractCommand implements PublicCommandInterface
{
    public function getName(): string
    {
        return '/start';
    }

    public function getDescription(): string
    {
        return 'Start the bot';
    }

    public function execute(BotApi $api, Update $update): void
    {
        $message = $update->getMessage();

        $api->call('sendMessage', [
            'chat_id' => $message->getChat()->getId(),
            'text' => "Use /sticker to generate AI stickers!\n\nExample: /sticker 🐱 happy orange cat\n\nhttps://sticker-bot.srv1.netlabs.dev",
            'reply_parameters' => json_encode(['message_id' => $message->getMessageId()]),
        ]);
    }
}