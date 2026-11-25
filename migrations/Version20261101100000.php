<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Picture : replaces_id (proposition de remplacement de la photo actuelle) ; Notification : type élargi à 50 caractères';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE picture ADD replaces_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE picture ADD CONSTRAINT FK_16DB4F899F193203 FOREIGN KEY (replaces_id) REFERENCES picture (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_16DB4F899F193203 ON picture (replaces_id)');
        $this->addSql('ALTER TABLE notification CHANGE type type VARCHAR(50) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification CHANGE type type VARCHAR(30) NOT NULL');
        $this->addSql('ALTER TABLE picture DROP FOREIGN KEY FK_16DB4F899F193203');
        $this->addSql('DROP INDEX IDX_16DB4F899F193203 ON picture');
        $this->addSql('ALTER TABLE picture DROP replaces_id');
    }
}
