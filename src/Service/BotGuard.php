<?php

namespace App\Service;

use App\Entity\StickerPack;
use App\Entity\User;
use App\Repository\StickerRepository;
use App\Repository\UserRepository;
use TelegramBot\Api\Types\Message;

readonly class BotGuard
{
    public function __construct(
        private UserRepository $userRepository,
        private StickerRepository $stickerRepository,
        private StickerService $stickerService,
        private TelegramMessenger $messenger,
    ) {
    }

    public function resolveUser(Message $message): ?User
    {
        $from = $message->getFrom();
        $user = $this->userRepository->findOrCreateByTelegramData(
            $from->getId(),
            $from->getFirstName() ?? 'User',
            $from->getUsername()
        );

        if ($user->isBanned()) {
            $this->messenger->reply($message, 'Your account has been suspended.');
            return null;
        }

        return $user;
    }

    public function checkDailyLimit(Message $message, User $user): bool
    {
        $recentCount = $this->stickerRepository->countRecentByUser($user->getId(), new \DateTime('-24 hours'));

        if ($recentCount >= $user->getDailyLimit()) {
            $this->messenger->reply($message, "You've reached your daily limit of {$user->getDailyLimit()} stickers. Try again tomorrow!");
            return false;
        }

        return true;
    }

    public function resolvePack(Message $message, User $user): ?StickerPack
    {
        try {
            return $this->stickerService->ensurePack($user);
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'PEER_ID_INVALID')) {
                $botUsername = $_ENV['TELEGRAM_BOT_USERNAME'] ?? 'the bot';
                $this->messenger->reply($message, "Please start a private chat with @$botUsername first, then try again.");
                return null;
            }
            throw $e;
        }
    }
}