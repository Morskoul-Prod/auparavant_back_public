<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration générée automatiquement : à adapter si besoin !
 */
final class Version20261028101607 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // cette migration up() est générée automatiquement, à adapter si besoin
        $this->addSql('ALTER TABLE comment ADD reply_to_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526CFFDF7169 FOREIGN KEY (reply_to_id) REFERENCES comment (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_9474526CFFDF7169 ON comment (reply_to_id)');
    }

    public function down(Schema $schema): void
    {
        // cette migration down() est générée automatiquement, à adapter si besoin
        $this->addSql('ALTER TABLE comment DROP FOREIGN KEY FK_9474526CFFDF7169');
        $this->addSql('DROP INDEX IDX_9474526CFFDF7169 ON comment');
        $this->addSql('ALTER TABLE comment DROP reply_to_id');
    }
}
