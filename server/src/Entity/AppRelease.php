<?php

namespace App\Entity;

use App\Repository\AppReleaseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Опубликованная сборка Android-клиента. Активна ровно одна: её приложение предлагает поставить,
 * если у него версия ниже. Сделать активной прошлую сборку — откат для телефонов, которые ещё не обновились
 * (Android не ставит старую версию поверх новой, поэтому обновившиеся остаются на новой).
 */
#[ORM\Entity(repositoryClass: AppReleaseRepository::class)]
class AppRelease
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** versionCode сборки: растёт с каждым изменением клиента (число коммитов в android/). */
    #[ORM\Column(unique: true)]
    private int $versionCode;

    #[ORM\Column(length: 64)]
    private string $versionName;

    /** Git-дерево android/, из которого собрано: по нему раскатка понимает, изменился ли клиент. */
    #[ORM\Column(length: 64)]
    private string $sourceRevision;

    #[ORM\Column(length: 64)]
    private string $sha256;

    #[ORM\Column(type: Types::BIGINT)]
    private string $size;

    #[ORM\Column(options: ['default' => false])]
    private bool $active = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(int $versionCode, string $versionName, string $sourceRevision, string $sha256, int $size)
    {
        $this->versionCode = $versionCode;
        $this->createdAt = new \DateTimeImmutable();
        $this->replaceBuild($versionName, $sourceRevision, $sha256, $size);
    }

    /** Та же версия собрана заново (например, после сбоя публикации). */
    public function replaceBuild(string $versionName, string $sourceRevision, string $sha256, int $size): void
    {
        $this->versionName = $versionName;
        $this->sourceRevision = $sourceRevision;
        $this->sha256 = $sha256;
        $this->size = (string) $size;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVersionCode(): int
    {
        return $this->versionCode;
    }

    public function getVersionName(): string
    {
        return $this->versionName;
    }

    public function getSourceRevision(): string
    {
        return $this->sourceRevision;
    }

    public function getSha256(): string
    {
        return $this->sha256;
    }

    public function getSize(): int
    {
        return (int) $this->size;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function __toString(): string
    {
        return \sprintf('%s (%d)', $this->versionName, $this->versionCode);
    }
}
