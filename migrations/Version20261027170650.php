<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration générée automatiquement : à adapter si besoin !
 */
final class Version20261027170650 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // cette migration up() est générée automatiquement, à adapter si besoin
        $this->addSql('CREATE TABLE report (id INT AUTO_INCREMENT NOT NULL, target_type VARCHAR(10) NOT NULL, target_id INT NOT NULL, reason VARCHAR(10) NOT NULL, details LONGTEXT DEFAULT NULL, status VARCHAR(10) DEFAULT \'open\' NOT NULL, created_at DATETIME NOT NULL, handled_at DATETIME DEFAULT NULL, reporter_id INT NOT NULL, handled_by_id INT DEFAULT NULL, INDEX IDX_C42F7784E1CFE6F5 (reporter_id), INDEX IDX_C42F7784FE65AF40 (handled_by_id), INDEX IDX_C42F77847B00651C41C64198158E0B66 (status, target_type, target_id), UNIQUE INDEX uniq_report_reporter_target (reporter_id, target_type, target_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE report ADD CONSTRAINT FK_C42F7784E1CFE6F5 FOREIGN KEY (reporter_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE report ADD CONSTRAINT FK_C42F7784FE65AF40 FOREIGN KEY (handled_by_id) REFERENCES user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // cette migration down() est générée automatiquement, à adapter si besoin
        $this->addSql('ALTER TABLE report DROP FOREIGN KEY FK_C42F7784E1CFE6F5');
        $this->addSql('ALTER TABLE report DROP FOREIGN KEY FK_C42F7784FE65AF40');
        $this->addSql('DROP TABLE report');
    }
}
