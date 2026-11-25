<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration générée automatiquement : à adapter si besoin !
 */
final class Version20261030171950 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // cette migration up() est générée automatiquement, à adapter si besoin
        $this->addSql('CREATE TABLE memory_like (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, memory_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_3502BBDCCC80CB3 (memory_id), INDEX IDX_3502BBDA76ED395 (user_id), UNIQUE INDEX uniq_memory_like_memory_user (memory_id, user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE memory_like ADD CONSTRAINT FK_3502BBDCCC80CB3 FOREIGN KEY (memory_id) REFERENCES memory (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE memory_like ADD CONSTRAINT FK_3502BBDA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // cette migration down() est générée automatiquement, à adapter si besoin
        $this->addSql('ALTER TABLE memory_like DROP FOREIGN KEY FK_3502BBDCCC80CB3');
        $this->addSql('ALTER TABLE memory_like DROP FOREIGN KEY FK_3502BBDA76ED395');
        $this->addSql('DROP TABLE memory_like');
    }
}
