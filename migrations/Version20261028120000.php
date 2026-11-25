<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notification comment_liked : likes_count et first_like_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification ADD likes_count INT DEFAULT NULL, ADD first_like_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification DROP likes_count, DROP first_like_id');
    }
}
