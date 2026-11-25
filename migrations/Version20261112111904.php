<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration générée automatiquement : à adapter si besoin !
 */
final class Version20261112111904 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // cette migration up() est générée automatiquement, à adapter si besoin
        $this->addSql('CREATE TABLE email_change (id INT AUTO_INCREMENT NOT NULL, old_email VARCHAR(180) NOT NULL, new_email VARCHAR(180) NOT NULL, created_at DATETIME NOT NULL, confirm_token_hash VARCHAR(64) DEFAULT NULL, confirm_expires_at DATETIME NOT NULL, confirmed_at DATETIME DEFAULT NULL, revert_token_hash VARCHAR(64) DEFAULT NULL, revert_expires_at DATETIME DEFAULT NULL, reverted_at DATETIME DEFAULT NULL, user_id INT NOT NULL, INDEX IDX_3DCD2DD0A76ED395 (user_id), INDEX idx_email_change_confirm_token (confirm_token_hash), INDEX idx_email_change_revert_token (revert_token_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE email_change ADD CONSTRAINT FK_3DCD2DD0A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // cette migration down() est générée automatiquement, à adapter si besoin
        $this->addSql('ALTER TABLE email_change DROP FOREIGN KEY FK_3DCD2DD0A76ED395');
        $this->addSql('DROP TABLE email_change');
    }
}
