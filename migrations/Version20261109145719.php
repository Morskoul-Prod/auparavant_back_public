<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Vérification de l'adresse email : colonnes de confirmation sur user.
 * Les comptes existants sont marqués confirmés (on ne bloque personne au déploiement).
 */
final class Version20261109145719 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Confirmation de l'adresse email des comptes";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD email_verified_at DATETIME DEFAULT NULL, ADD email_verification_token_hash VARCHAR(64) DEFAULT NULL, ADD email_verification_expires_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_user_email_verification_token ON user (email_verification_token_hash)');
        $this->addSql('UPDATE user SET email_verified_at = NOW()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_user_email_verification_token ON user');
        $this->addSql('ALTER TABLE user DROP email_verified_at, DROP email_verification_token_hash, DROP email_verification_expires_at');
    }
}
