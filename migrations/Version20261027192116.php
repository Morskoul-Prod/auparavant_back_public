<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration générée automatiquement : à adapter si besoin !
 */
final class Version20261027192116 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // cette migration up() est générée automatiquement, à adapter si besoin
        $this->addSql('ALTER TABLE comment ADD hidden_at DATETIME DEFAULT NULL, ADD hidden_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526C750EF4DA FOREIGN KEY (hidden_by_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_9474526C750EF4DA ON comment (hidden_by_id)');
    }

    public function down(Schema $schema): void
    {
        // cette migration down() est générée automatiquement, à adapter si besoin
        $this->addSql('ALTER TABLE comment DROP FOREIGN KEY FK_9474526C750EF4DA');
        $this->addSql('DROP INDEX IDX_9474526C750EF4DA ON comment');
        $this->addSql('ALTER TABLE comment DROP hidden_at, DROP hidden_by_id');
    }
}
