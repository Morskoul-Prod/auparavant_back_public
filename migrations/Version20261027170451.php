<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration générée automatiquement : à adapter si besoin !
 */
final class Version20261027170451 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // cette migration up() est générée automatiquement, à adapter si besoin
        $this->addSql('CREATE TABLE notification (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(30) NOT NULL, created_at DATETIME NOT NULL, seen_at DATETIME DEFAULT NULL, recipient_id INT NOT NULL, actor_id INT DEFAULT NULL, memory_id INT NOT NULL, comment_id INT DEFAULT NULL, INDEX IDX_BF5476CAE92F8F78 (recipient_id), INDEX IDX_BF5476CA10DAF24A (actor_id), INDEX IDX_BF5476CACCC80CB3 (memory_id), INDEX IDX_BF5476CAF8697D13 (comment_id), INDEX IDX_BF5476CAE92F8F78DE907562 (recipient_id, seen_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAE92F8F78 FOREIGN KEY (recipient_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA10DAF24A FOREIGN KEY (actor_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CACCC80CB3 FOREIGN KEY (memory_id) REFERENCES memory (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAF8697D13 FOREIGN KEY (comment_id) REFERENCES comment (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // cette migration down() est générée automatiquement, à adapter si besoin
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CAE92F8F78');
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CA10DAF24A');
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CACCC80CB3');
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CAF8697D13');
        $this->addSql('DROP TABLE notification');
    }
}
