<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Modération des photos : statut (les photos existantes passent en « approved » via la valeur par défaut)
 * et auteur de l'envoi.
 */
final class Version20261027092726 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute picture.status (défaut approved) et picture.uploaded_by_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE picture ADD status VARCHAR(20) DEFAULT \'approved\' NOT NULL, ADD uploaded_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE picture ADD CONSTRAINT FK_16DB4F89A2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_16DB4F89A2B28FE8 ON picture (uploaded_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE picture DROP FOREIGN KEY FK_16DB4F89A2B28FE8');
        $this->addSql('DROP INDEX IDX_16DB4F89A2B28FE8 ON picture');
        $this->addSql('ALTER TABLE picture DROP status, DROP uploaded_by_id');
    }
}
