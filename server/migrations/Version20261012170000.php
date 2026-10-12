<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012170000 extends AbstractMigration
{
    /** Языки стартовых изданий; остальные — русские (значение по умолчанию). */
    private const LANGUAGES = [
        'en' => ['cnn', 'nypost', 'nyt', 'bbc', 'times'],
        'fr' => ['lemonde'],
        'uk' => ['pravda_ua'],
    ];

    public function getDescription(): string
    {
        return 'Новости: язык издания и перевод новостей на русский';
    }

    public function up(Schema $schema): void
    {
        foreach ([$this->newsSourceDiff(), $this->newsItemDiff()] as $diff) {
            foreach ($this->platform->getAlterTableSQL($diff) as $sql) {
                $this->addSql($sql);
            }
        }
        foreach (self::LANGUAGES as $language => $codes) {
            $this->addSql(sprintf(
                "UPDATE news_source SET language = '%s' WHERE code IN (%s)",
                $language,
                implode(', ', array_map(static fn (string $code) => "'$code'", $codes)),
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE news_item DROP COLUMN translated_title');
        $this->addSql('ALTER TABLE news_item DROP COLUMN translated_summary');
        $this->addSql('ALTER TABLE news_item DROP COLUMN translated_content');
        $this->addSql('ALTER TABLE news_item DROP COLUMN headline_translation_attempts');
        $this->addSql('ALTER TABLE news_item DROP COLUMN content_translation_attempts');
        $this->addSql('ALTER TABLE news_source DROP COLUMN language');
    }

    /** Колонки через Schema API DBAL — SQL под текущую платформу, как в PortableSchema. */
    private function newsSourceDiff(): TableDiff
    {
        $manager = $this->connection->createSchemaManager();
        $table = $manager->introspectTableByUnquotedName('news_source');
        $changed = clone $table;
        $changed->addColumn('language', Types::STRING, ['length' => 8, 'notnull' => true, 'default' => 'ru']);

        return $manager->createComparator()->compareTables($table, $changed);
    }

    private function newsItemDiff(): TableDiff
    {
        $manager = $this->connection->createSchemaManager();
        $table = $manager->introspectTableByUnquotedName('news_item');
        $changed = clone $table;
        $changed->addColumn('translated_title', Types::STRING, ['length' => 1000, 'notnull' => false]);
        $changed->addColumn('translated_summary', Types::TEXT, ['notnull' => false]);
        $changed->addColumn('translated_content', Types::TEXT, ['notnull' => false]);
        $changed->addColumn('headline_translation_attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]);
        $changed->addColumn('content_translation_attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]);

        return $manager->createComparator()->compareTables($table, $changed);
    }
}
