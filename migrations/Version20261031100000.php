<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User : credentials_changed_at (invalidation des JWT après changement d\'email ou de mot de passe)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD credentials_changed_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP credentials_changed_at');
    }
}
