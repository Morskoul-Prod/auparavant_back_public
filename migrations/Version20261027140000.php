<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notification de refus des photos : motif, date de modération, date de vue par l'auteur.
 * Colonnes nullables : les photos existantes ne sont pas impactées. Les refus antérieurs
 * (sans motif) sont marqués comme déjà vus pour ne pas générer de notifications vides.
 */
final class Version20261027140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute picture.rejection_reason, picture.moderated_at et picture.rejection_seen_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE picture ADD rejection_reason VARCHAR(255) DEFAULT NULL, ADD moderated_at DATETIME DEFAULT NULL, ADD rejection_seen_at DATETIME DEFAULT NULL');
        $this->addSql('UPDATE picture SET rejection_seen_at = NOW() WHERE status = \'rejected\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE picture DROP rejection_reason, DROP moderated_at, DROP rejection_seen_at');
    }
}
