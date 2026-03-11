<?php

namespace App\Repository;

use App\Entity\StickerPack;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StickerPack>
 */
class StickerPackRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StickerPack::class);
    }

    public function findByUser(User $user): ?StickerPack
    {
        return $this->findOneBy(['user' => $user]);
    }

    public function save(StickerPack $stickerPack): void
    {
        $this->getEntityManager()->persist($stickerPack);
        $this->getEntityManager()->flush();
    }
}