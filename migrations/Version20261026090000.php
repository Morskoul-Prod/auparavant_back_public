<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Aligne memory.year sur picture_date quand une date exacte existe
 * (l'année effective est ainsi fiable pour les tris et la fresque).
 */
final class Version20261026090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Synchronise memory.year avec YEAR(picture_date) quand la date exacte est renseignée';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE memory SET year = YEAR(picture_date) WHERE picture_date IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Données dérivées : rien à restaurer
    }
}
