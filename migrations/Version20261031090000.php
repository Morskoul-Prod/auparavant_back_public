<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pseudonyme (non unique) à la place de prénom/nom (minimisation RGPD).
 */
final class Version20261031090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User : pseudonym (repris du prénom, à défaut de la partie locale de l\'email) ; suppression de firstname et lastname';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD pseudonym VARCHAR(30) DEFAULT NULL');
        // Prénom s'il fait au moins 2 caractères, sinon partie locale de l'email ; tronqué à 30 caractères
        $this->addSql("UPDATE user SET pseudonym = LEFT(TRIM(CASE WHEN CHAR_LENGTH(TRIM(firstname)) >= 2 THEN firstname ELSE SUBSTRING_INDEX(email, '@', 1) END), 30)");
        $this->addSql('ALTER TABLE user MODIFY pseudonym VARCHAR(30) NOT NULL, DROP firstname, DROP lastname');
    }

    public function down(Schema $schema): void
    {
        // Les nom et prénom d'origine sont perdus : le pseudonyme sert de prénom, le nom est vide
        $this->addSql("ALTER TABLE user ADD firstname VARCHAR(30) NOT NULL DEFAULT '', ADD lastname VARCHAR(30) NOT NULL DEFAULT ''");
        $this->addSql('UPDATE user SET firstname = pseudonym');
        $this->addSql('ALTER TABLE user DROP pseudonym, ALTER firstname DROP DEFAULT, ALTER lastname DROP DEFAULT');
    }
}
