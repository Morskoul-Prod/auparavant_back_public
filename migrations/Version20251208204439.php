<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration générée automatiquement : à adapter si besoin !
 */
final class Version20251208204439 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // cette migration up() est générée automatiquement, à adapter si besoin
        $this->addSql('ALTER TABLE location CHANGE district district VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // cette migration down() est générée automatiquement, à adapter si besoin
        $this->addSql('ALTER TABLE location CHANGE district district VARCHAR(20) DEFAULT NULL');
    }
}
