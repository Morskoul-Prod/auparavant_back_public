<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration générée automatiquement : à adapter si besoin !
 */
final class Version20260323163522 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Conversion du champ type de Place pour utiliser l\'enum PlaceType';
    }

    public function up(Schema $schema): void
    {
        // cette migration up() est générée automatiquement, à adapter si besoin
        $this->addSql('ALTER TABLE place CHANGE type type VARCHAR(255) NOT NULL');
        
        // Migration des valeurs existantes vers les valeurs d'enum si nécessaire
        // Si des données existent déjà, vous pouvez ajouter des requêtes UPDATE ici
        // Exemple : $this->addSql("UPDATE place SET type = 'autre' WHERE type NOT IN ('maison', 'restaurant', ...)");
    }

    public function down(Schema $schema): void
    {
        // cette migration down() est générée automatiquement, à adapter si besoin
        $this->addSql('ALTER TABLE place CHANGE type type VARCHAR(30) NOT NULL');
    }
}
