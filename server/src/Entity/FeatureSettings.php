<?php

namespace App\Entity;

use App\Repository\FeatureSettingsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Глобальные переключатели функциональных блоков и конкретных функций ИИ.
 * В базе всегда хранится одна запись (id=1), управляемая из админки.
 */
#[ORM\Entity(repositoryClass: FeatureSettingsRepository::class)]
#[ORM\Table(name: 'feature_settings')]
class FeatureSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $deviceDataEnabled = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $contactsEnabled = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $vaultEnabled = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $appsEnabled = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $calendarEnabled = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $browsingEnabled = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $newsEnabled = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $marketEnabled = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $vpnEnabled = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $aiNewsEnabled = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $aiNewsTranslateEnabled = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $aiNewsStorylinesEnabled = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $aiNewsDigestEnabled = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $aiMarketEnabled = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $aiMarketNewsImpactEnabled = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $aiMarketAnalysisEnabled = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function isDeviceDataEnabled(): bool
    {
        return $this->deviceDataEnabled;
    }

    public function setDeviceDataEnabled(bool $enabled): void
    {
        $this->deviceDataEnabled = $enabled;
        $this->touch();
    }

    public function isContactsEnabled(): bool
    {
        return $this->contactsEnabled;
    }

    public function setContactsEnabled(bool $enabled): void
    {
        $this->contactsEnabled = $enabled;
        $this->touch();
    }

    public function isVaultEnabled(): bool
    {
        return $this->vaultEnabled;
    }

    public function setVaultEnabled(bool $enabled): void
    {
        $this->vaultEnabled = $enabled;
        $this->touch();
    }

    public function isAppsEnabled(): bool
    {
        return $this->appsEnabled;
    }

    public function setAppsEnabled(bool $enabled): void
    {
        $this->appsEnabled = $enabled;
        $this->touch();
    }

    public function isCalendarEnabled(): bool
    {
        return $this->calendarEnabled;
    }

    public function setCalendarEnabled(bool $enabled): void
    {
        $this->calendarEnabled = $enabled;
        $this->touch();
    }

    public function isBrowsingEnabled(): bool
    {
        return $this->browsingEnabled;
    }

    public function setBrowsingEnabled(bool $enabled): void
    {
        $this->browsingEnabled = $enabled;
        $this->touch();
    }

    public function isNewsEnabled(): bool
    {
        return $this->newsEnabled;
    }

    public function setNewsEnabled(bool $enabled): void
    {
        $this->newsEnabled = $enabled;
        $this->touch();
    }

    public function isMarketEnabled(): bool
    {
        return $this->marketEnabled;
    }

    public function setMarketEnabled(bool $enabled): void
    {
        $this->marketEnabled = $enabled;
        $this->touch();
    }

    public function isVpnEnabled(): bool
    {
        return $this->vpnEnabled;
    }

    public function setVpnEnabled(bool $enabled): void
    {
        $this->vpnEnabled = $enabled;
        $this->touch();
    }

    public function isAiNewsEnabled(): bool
    {
        return $this->aiNewsEnabled;
    }

    public function setAiNewsEnabled(bool $enabled): void
    {
        $this->aiNewsEnabled = $enabled;
        $this->touch();
    }

    public function isAiNewsTranslateEnabled(): bool
    {
        return $this->aiNewsTranslateEnabled;
    }

    public function setAiNewsTranslateEnabled(bool $enabled): void
    {
        $this->aiNewsTranslateEnabled = $enabled;
        $this->touch();
    }

    public function isAiNewsStorylinesEnabled(): bool
    {
        return $this->aiNewsStorylinesEnabled;
    }

    public function setAiNewsStorylinesEnabled(bool $enabled): void
    {
        $this->aiNewsStorylinesEnabled = $enabled;
        $this->touch();
    }

    public function isAiNewsDigestEnabled(): bool
    {
        return $this->aiNewsDigestEnabled;
    }

    public function setAiNewsDigestEnabled(bool $enabled): void
    {
        $this->aiNewsDigestEnabled = $enabled;
        $this->touch();
    }

    public function isAiMarketEnabled(): bool
    {
        return $this->aiMarketEnabled;
    }

    public function setAiMarketEnabled(bool $enabled): void
    {
        $this->aiMarketEnabled = $enabled;
        $this->touch();
    }

    public function isAiMarketNewsImpactEnabled(): bool
    {
        return $this->aiMarketNewsImpactEnabled;
    }

    public function setAiMarketNewsImpactEnabled(bool $enabled): void
    {
        $this->aiMarketNewsImpactEnabled = $enabled;
        $this->touch();
    }

    public function isAiMarketAnalysisEnabled(): bool
    {
        return $this->aiMarketAnalysisEnabled;
    }

    public function setAiMarketAnalysisEnabled(bool $enabled): void
    {
        $this->aiMarketAnalysisEnabled = $enabled;
        $this->touch();
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
