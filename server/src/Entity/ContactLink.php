<?php

namespace App\Entity;

use App\Repository\ContactLinkRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Связь серверного контакта с локальным контактом устройства (LOOKUP_KEY).
 * Нужна, чтобы один контакт на нескольких телефонах не превращался в дубли.
 */
#[ORM\Entity(repositoryClass: ContactLinkRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_link_device_external', columns: ['device_id', 'external_id'])]
class ContactLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Device $device;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Contact $contact;

    #[ORM\Column(length: 255)]
    private string $externalId;

    public function __construct(Device $device, Contact $contact, string $externalId)
    {
        $this->device = $device;
        $this->contact = $contact;
        $this->externalId = $externalId;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDevice(): Device
    {
        return $this->device;
    }

    public function getContact(): Contact
    {
        return $this->contact;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }
}
