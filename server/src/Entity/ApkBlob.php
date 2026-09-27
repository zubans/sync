<?php

namespace App\Entity;

use App\Repository\ApkBlobRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Загруженный APK-файл. Хранится по содержимому (SHA-256): одинаковый файл у разных
 * пользователей лежит один раз, но скачать его может только тот, у кого он был установлен.
 */
#[ORM\Entity(repositoryClass: ApkBlobRepository::class)]
class ApkBlob
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $sha256;

    #[ORM\Column(type: Types::BIGINT)]
    private string $size;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $sha256, int $size)
    {
        $this->sha256 = $sha256;
        $this->size = (string) $size;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSha256(): string
    {
        return $this->sha256;
    }

    public function getSize(): int
    {
        return (int) $this->size;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
