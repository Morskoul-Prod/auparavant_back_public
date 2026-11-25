<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration générée automatiquement : à adapter si besoin !
 */
final class Version20251208170453 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // cette migration up() est générée automatiquement, à adapter si besoin
        $this->addSql('ALTER TABLE memory ADD year INT DEFAULT NULL, CHANGE picture_date picture_date DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE picture ADD type VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // cette migration down() est générée automatiquement, à adapter si besoin
        $this->addSql('ALTER TABLE memory DROP year, CHANGE picture_date picture_date DATETIME NOT NULL');
        $this->addSql('ALTER TABLE picture DROP type');
    }
}
