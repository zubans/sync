<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012190000 extends AbstractMigration
{
    /**
     * Иран и Египет — только в сводку: код, название, ленты (через пробел), язык.
     * IRNA — государственное агентство Ирана, по-персидски. Youm7 — крупнейший новостной сайт Египта,
     * по-арабски: «Срочные новости» и «Египетские репортажи». Время в его ленте записано по-арабски
     * и не разбирается — время новости = когда сервер впервые её увидел, как у CNN. Сайт «Аль-Ахрам»
     * отвечает 403, у Masrawy время без часового пояса.
     */
    private const SOURCES = [
        ['irna', 'IRNA (Иран)', 'https://www.irna.ir/rss', 'fa'],
        ['youm7', 'Youm7 (Египет)', 'https://www.youm7.com/rss/SectionRss?SectionID=65 https://www.youm7.com/rss/SectionRss?SectionID=97', 'ar'],
    ];

    public function getDescription(): string
    {
        return 'Новости: только в сводку — IRNA (Иран), Youm7 (Египет)';
    }

    public function up(Schema $schema): void
    {
        foreach (self::SOURCES as [$code, $title, $url, $language]) {
            $this->addSql(
                'INSERT INTO news_source (code, title, url, format, language, enabled, show_in_widget) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$code, $title, $url, 'rss', $language, true, false],
                [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::BOOLEAN, ParameterType::BOOLEAN],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM news_source WHERE code IN ('irna', 'youm7')");
    }
}
