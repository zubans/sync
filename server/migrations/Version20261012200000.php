<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Новости: только в сводку — The National (ОАЭ)';
    }

    /**
     * The National — газета Абу-Даби, принадлежит государственному холдингу; по-английски, с полным
     * текстом в ленте. У арабоязычных «Аль-Халидж», «Аль-Баян», «Аль-Иттихад» лент нет или они пустые,
     * у WAM — только карта сайта без новостей.
     */
    public function up(Schema $schema): void
    {
        $this->addSql(
            'INSERT INTO news_source (code, title, url, format, language, enabled, show_in_widget) VALUES (?, ?, ?, ?, ?, ?, ?)',
            ['thenational', 'The National (ОАЭ)', 'https://www.thenationalnews.com/arc/outboundfeeds/rss/?outputType=xml', 'rss', 'en', true, false],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::BOOLEAN, ParameterType::BOOLEAN],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM news_source WHERE code = 'thenational'");
    }
}
