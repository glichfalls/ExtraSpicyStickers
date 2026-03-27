<?php

namespace App\Service;

use TelegramBot\Api\BotApi;
use TelegramBot\Api\Types\Message;

readonly class TelegramMessenger
{
    public function __construct(
        private BotApi $botApi,
    ) {
    }

    public function reply(Message $message, string $text): void
    {
        $params = [
            'chat_id' => $message->getChat()->getId(),
            'text' => $text,
            'reply_parameters' => json_encode([
                'message_id' => $message->getMessageId(),
                'allow_sending_without_reply' => true,
            ]),
        ];

        $this->addThreadId($params, $message);

        $this->botApi->call('sendMessage', $params);
    }

    public function replySticker(Message $message, string $fileId): void
    {
        $params = [
            'chat_id' => $message->getChat()->getId(),
            'sticker' => $fileId,
            'reply_parameters' => json_encode([
                'message_id' => $message->getMessageId(),
                'allow_sending_without_reply' => true,
            ]),
        ];

        $this->addThreadId($params, $message);

        $this->botApi->call('sendSticker', $params);
    }

    public function send(int $chatId, string $text, ?int $threadId = null): void
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
        ];

        if ($threadId !== null) {
            $params['message_thread_id'] = $threadId;
        }

        $this->botApi->call('sendMessage', $params);
    }

    private function addThreadId(array &$params, Message $message): void
    {
        if (method_exists($message, 'getMessageThreadId')) {
            $threadId = $message->getMessageThreadId();
            if ($threadId !== null) {
                $params['message_thread_id'] = $threadId;
            }
        }
    }
}
