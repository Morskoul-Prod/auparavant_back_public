<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration générée automatiquement : à adapter si besoin !
 */
final class Version20261025211825 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout de address_id (identifiant API Adresse) sur Location avec index non unique';
    }

    public function up(Schema $schema): void
    {
        // cette migration up() est générée automatiquement, à adapter si besoin
        $this->addSql('ALTER TABLE location ADD address_id VARCHAR(50) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_location_address_id ON location (address_id)');
    }

    public function down(Schema $schema): void
    {
        // cette migration down() est générée automatiquement, à adapter si besoin
        $this->addSql('DROP INDEX idx_location_address_id ON location');
        $this->addSql('ALTER TABLE location DROP address_id');
    }
}
