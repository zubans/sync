<?php

namespace App\Service;

use App\Entity\FeatureSettings;
use App\Repository\FeatureSettingsRepository;
use App\Security\FeatureDisabledException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Единая точка проверки и обновления переключателей блоков и конкретных ИИ-функций.
 */
final class FeatureManager
{
    public const DEVICE_DATA = 'device_data';
    public const CONTACTS = 'contacts';
    public const VAULT = 'vault';
    public const APPS = 'apps';
    public const CALENDAR = 'calendar';
    public const BROWSING = 'browsing';

    public const NEWS = 'news';
    public const MARKET = 'market';
    public const VPN = 'vpn';

    public const AI_NEWS = 'ai_news';
    public const AI_NEWS_TRANSLATE = 'ai_news_translate';
    public const AI_NEWS_STORYLINES = 'ai_news_storylines';
    public const AI_NEWS_DIGEST = 'ai_news_digest';

    public const AI_MARKET = 'ai_market';
    public const AI_MARKET_NEWS_IMPACT = 'ai_market_news_impact';
    public const AI_MARKET_ANALYSIS = 'ai_market_analysis';

    /** @var array<string, string> Человекочитаемые названия блоков и функций */
    public const LABELS = [
        self::DEVICE_DATA => 'Блок «Данные устройства»',
        self::CONTACTS => 'Контакты, семьи и дубликаты',
        self::VAULT => 'Менеджер паролей',
        self::APPS => 'Приложения и APK-бэкапы',
        self::CALENDAR => 'Календарь и семейный доступ',
        self::BROWSING => 'История браузеров',
        self::NEWS => 'Блок «Новости»',
        self::MARKET => 'Блок «Рынок акций (Мосбиржа)»',
        self::VPN => 'Блок «VPN и шифрование (CEP / Argon4 / ocserv)»',
        self::AI_NEWS => 'ИИ новостей (группа)',
        self::AI_NEWS_TRANSLATE => 'ИИ: перевод новостей',
        self::AI_NEWS_STORYLINES => 'ИИ: сюжетные линии и эмбеддинги',
        self::AI_NEWS_DIGEST => 'ИИ: часовые и суточные сводки новостей',
        self::AI_MARKET => 'ИИ рынка акций (группа)',
        self::AI_MARKET_NEWS_IMPACT => 'ИИ: оценка влияния новостей на Мосбиржу',
        self::AI_MARKET_ANALYSIS => 'ИИ: анализ инструментов и покупок Мосбиржи',
    ];

    public function __construct(
        private readonly FeatureSettingsRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getSettings(): FeatureSettings
    {
        return $this->repository->getOrCreate();
    }

    public function isEnabled(string $feature): bool
    {
        $s = $this->getSettings();

        return match ($feature) {
            self::DEVICE_DATA => $s->isDeviceDataEnabled(),
            self::CONTACTS => $s->isDeviceDataEnabled() && $s->isContactsEnabled(),
            self::VAULT => $s->isDeviceDataEnabled() && $s->isVaultEnabled(),
            self::APPS => $s->isDeviceDataEnabled() && $s->isAppsEnabled(),
            self::CALENDAR => $s->isDeviceDataEnabled() && $s->isCalendarEnabled(),
            self::BROWSING => $s->isDeviceDataEnabled() && $s->isBrowsingEnabled(),
            self::NEWS => $s->isNewsEnabled(),
            self::MARKET => $s->isMarketEnabled(),
            self::VPN => $s->isVpnEnabled(),
            self::AI_NEWS => $s->isNewsEnabled() && $s->isAiNewsEnabled(),
            self::AI_NEWS_TRANSLATE => $s->isNewsEnabled() && $s->isAiNewsEnabled() && $s->isAiNewsTranslateEnabled(),
            self::AI_NEWS_STORYLINES => $s->isNewsEnabled() && $s->isAiNewsEnabled() && $s->isAiNewsStorylinesEnabled(),
            self::AI_NEWS_DIGEST => $s->isNewsEnabled() && $s->isAiNewsEnabled() && $s->isAiNewsDigestEnabled(),
            self::AI_MARKET => $s->isMarketEnabled() && $s->isAiMarketEnabled(),
            self::AI_MARKET_NEWS_IMPACT => $s->isNewsEnabled() && $s->isMarketEnabled() && $s->isAiMarketEnabled() && $s->isAiMarketNewsImpactEnabled(),
            self::AI_MARKET_ANALYSIS => $s->isMarketEnabled() && $s->isAiMarketEnabled() && $s->isAiMarketAnalysisEnabled(),
            default => throw new \InvalidArgumentException(\sprintf('Неизвестный флаг функциональности «%s».', $feature)),
        };
    }

    public function assertEnabled(string $feature, ?string $customMessage = null): void
    {
        if ($this->isEnabled($feature)) {
            return;
        }
        $label = self::LABELS[$feature] ?? $feature;
        $message = $customMessage ?? \sprintf('Функциональность «%s» отключена в разделе «Модули и функции ИИ».', $label);

        throw new FeatureDisabledException($feature, $message);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function updateFromFormInput(array $input): FeatureSettings
    {
        $s = $this->getSettings();
        $bool = static fn (string $key): bool => isset($input[$key]) && \in_array($input[$key], ['1', 1, true, 'true', 'on'], true);

        $s->setDeviceDataEnabled($bool(self::DEVICE_DATA));
        $s->setContactsEnabled($bool(self::CONTACTS));
        $s->setVaultEnabled($bool(self::VAULT));
        $s->setAppsEnabled($bool(self::APPS));
        $s->setCalendarEnabled($bool(self::CALENDAR));
        $s->setBrowsingEnabled($bool(self::BROWSING));
        $s->setNewsEnabled($bool(self::NEWS));
        $s->setMarketEnabled($bool(self::MARKET));
        $s->setVpnEnabled($bool(self::VPN));

        $s->setAiNewsEnabled($bool(self::AI_NEWS));
        $s->setAiNewsTranslateEnabled($bool(self::AI_NEWS_TRANSLATE));
        $s->setAiNewsStorylinesEnabled($bool(self::AI_NEWS_STORYLINES));
        $s->setAiNewsDigestEnabled($bool(self::AI_NEWS_DIGEST));

        $s->setAiMarketEnabled($bool(self::AI_MARKET));
        $s->setAiMarketNewsImpactEnabled($bool(self::AI_MARKET_NEWS_IMPACT));
        $s->setAiMarketAnalysisEnabled($bool(self::AI_MARKET_ANALYSIS));

        $this->entityManager->persist($s);
        $this->entityManager->flush();

        return $s;
    }

    /**
     * @return list<string> Рекомендуемые профили Docker Compose, соответствующие активным блокам
     */
    public function recommendedComposeProfiles(): array
    {
        $profiles = [];
        if ($this->isEnabled(self::NEWS)) {
            $profiles[] = 'news';
        }
        if ($this->isEnabled(self::VPN)) {
            $profiles[] = 'vpn';
        }

        return $profiles;
    }

    /**
     * @return array<string, bool>
     */
    public function getEffectiveMap(): array
    {
        $map = [];
        foreach (array_keys(self::LABELS) as $key) {
            $map[$key] = $this->isEnabled($key);
        }

        return $map;
    }
}
