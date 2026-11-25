<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Utilisateur : date d\'acceptation des CGU à l\'inscription';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD terms_accepted_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP terms_accepted_at');
    }
}
