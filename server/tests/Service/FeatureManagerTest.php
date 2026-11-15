<?php

namespace App\Tests\Service;

use App\Service\FeatureManager;
use App\Tests\DatabaseWebTestCase;

final class FeatureManagerTest extends DatabaseWebTestCase
{
    private function defaultInput(array $overrides = []): array
    {
        $base = [
            FeatureManager::DEVICE_DATA => '1',
            FeatureManager::CONTACTS => '1',
            FeatureManager::VAULT => '1',
            FeatureManager::APPS => '1',
            FeatureManager::CALENDAR => '1',
            FeatureManager::BROWSING => '1',
            FeatureManager::NEWS => '1',
            FeatureManager::MARKET => '1',
            FeatureManager::VPN => '1',
            FeatureManager::AI_NEWS => '1',
            FeatureManager::AI_NEWS_TRANSLATE => '1',
            FeatureManager::AI_NEWS_STORYLINES => '1',
            FeatureManager::AI_NEWS_DIGEST => '1',
            FeatureManager::AI_MARKET => '1',
            FeatureManager::AI_MARKET_NEWS_IMPACT => '1',
            FeatureManager::AI_MARKET_ANALYSIS => '1',
        ];

        foreach ($overrides as $k => $v) {
            if ($v === false || $v === null || $v === '0') {
                unset($base[$k]);
            } else {
                $base[$k] = '1';
            }
        }

        return $base;
    }

    public function testHierarchyAndGranularAiToggles(): void
    {
        $fm = static::getContainer()->get(FeatureManager::class);

        // По умолчанию все блоки и ИИ-функции включены.
        self::assertTrue($fm->isEnabled(FeatureManager::CONTACTS));
        self::assertTrue($fm->isEnabled(FeatureManager::AI_NEWS_TRANSLATE));
        self::assertTrue($fm->isEnabled(FeatureManager::AI_MARKET_ANALYSIS));
        self::assertSame(['news', 'vpn'], $fm->recommendedComposeProfiles());

        // Отключение только перевода новостей не затрагивает сюжеты, сводки и сбор.
        $fm->updateFromFormInput($this->defaultInput([
            FeatureManager::AI_NEWS_TRANSLATE => false,
        ]));
        self::assertTrue($fm->isEnabled(FeatureManager::NEWS));
        self::assertTrue($fm->isEnabled(FeatureManager::AI_NEWS));
        self::assertFalse($fm->isEnabled(FeatureManager::AI_NEWS_TRANSLATE));
        self::assertTrue($fm->isEnabled(FeatureManager::AI_NEWS_STORYLINES));
        self::assertTrue($fm->isEnabled(FeatureManager::AI_NEWS_DIGEST));

        // Отключение мастер-блока device_data выключает все подпункты телефона.
        $fm->updateFromFormInput($this->defaultInput([
            FeatureManager::DEVICE_DATA => false,
        ]));
        self::assertFalse($fm->isEnabled(FeatureManager::CONTACTS));
        self::assertFalse($fm->isEnabled(FeatureManager::VAULT));
        self::assertFalse($fm->isEnabled(FeatureManager::APPS));
        self::assertFalse($fm->isEnabled(FeatureManager::CALENDAR));
        self::assertFalse($fm->isEnabled(FeatureManager::BROWSING));

        // Отключение news выключает также ai_news_* и ai_market_news_impact, оставляя ai_market_analysis.
        $fm->updateFromFormInput($this->defaultInput([
            FeatureManager::NEWS => false,
            FeatureManager::VPN => false,
        ]));
        self::assertFalse($fm->isEnabled(FeatureManager::AI_NEWS));
        self::assertFalse($fm->isEnabled(FeatureManager::AI_NEWS_DIGEST));
        self::assertFalse($fm->isEnabled(FeatureManager::AI_MARKET_NEWS_IMPACT));
        self::assertTrue($fm->isEnabled(FeatureManager::MARKET));
        self::assertTrue($fm->isEnabled(FeatureManager::AI_MARKET_ANALYSIS));
        self::assertSame([], $fm->recommendedComposeProfiles());
    }
}
