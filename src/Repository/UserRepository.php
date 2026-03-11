<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOrCreateByTelegramData(int $telegramId, string $firstName, ?string $username = null): User
    {
        $user = $this->findOneBy(['telegramId' => $telegramId]);

        if ($user === null) {
            $user = new User();
            $user->setTelegramId($telegramId);
        }

        $user->setUsername($username);
        $user->setFirstName($firstName);

        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();

        return $user;
    }
}