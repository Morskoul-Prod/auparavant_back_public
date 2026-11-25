<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration générée automatiquement : à adapter si besoin !
 */
final class Version20261027172905 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // cette migration up() est générée automatiquement, à adapter si besoin
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY `FK_BF5476CACCC80CB3`');
        $this->addSql('ALTER TABLE notification ADD memory_title VARCHAR(255) DEFAULT NULL, ADD excerpt VARCHAR(255) DEFAULT NULL, ADD reason VARCHAR(255) DEFAULT NULL, CHANGE memory_id memory_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CACCC80CB3 FOREIGN KEY (memory_id) REFERENCES memory (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // cette migration down() est générée automatiquement, à adapter si besoin
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CACCC80CB3');
        $this->addSql('ALTER TABLE notification DROP memory_title, DROP excerpt, DROP reason, CHANGE memory_id memory_id INT NOT NULL');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT `FK_BF5476CACCC80CB3` FOREIGN KEY (memory_id) REFERENCES memory (id) ON DELETE CASCADE');
    }
}
