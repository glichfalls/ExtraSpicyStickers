<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'bigint', unique: true)]
    private int $telegramId;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $username = null;

    #[ORM\Column(length: 255)]
    private string $firstName;

    #[ORM\Column(options: ['default' => 5])]
    private int $dailyLimit = 5;

    #[ORM\Column(options: ['default' => false])]
    private bool $banned = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $isAdmin = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $password = null;

    /** @var Collection<int, StickerPack> */
    #[ORM\OneToMany(targetEntity: StickerPack::class, mappedBy: 'user')]
    private Collection $stickerPacks;

    #[ORM\ManyToOne(targetEntity: StickerPack::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?StickerPack $activeStickerPack = null;

    public function __construct()
    {
        $this->stickerPacks = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTelegramId(): int
    {
        return $this->telegramId;
    }

    public function setTelegramId(int $telegramId): static
    {
        $this->telegramId = $telegramId;

        return $this;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(?string $username): static
    {
        $this->username = $username;

        return $this;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getDailyLimit(): int
    {
        return $this->dailyLimit;
    }

    public function setDailyLimit(int $dailyLimit): static
    {
        $this->dailyLimit = $dailyLimit;

        return $this;
    }

    public function isBanned(): bool
    {
        return $this->banned;
    }

    public function setBanned(bool $banned): static
    {
        $this->banned = $banned;

        return $this;
    }

    public function isAdmin(): bool
    {
        return $this->isAdmin;
    }

    public function setIsAdmin(bool $isAdmin): static
    {
        $this->isAdmin = $isAdmin;

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /** @return Collection<int, StickerPack> */
    public function getStickerPacks(): Collection
    {
        return $this->stickerPacks;
    }

    public function getActiveStickerPack(): ?StickerPack
    {
        return $this->activeStickerPack;
    }

    public function setActiveStickerPack(?StickerPack $pack): static
    {
        $this->activeStickerPack = $pack;

        return $this;
    }
}
