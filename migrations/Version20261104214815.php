<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration générée automatiquement : à adapter si besoin !
 */
final class Version20261104214815 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Photo actuelle proposée : photo « avant » suggérée pour le comparateur (appliquée à l\'approbation)';
    }

    public function up(Schema $schema): void
    {
        // cette migration up() est générée automatiquement, à adapter si besoin
        $this->addSql('ALTER TABLE picture ADD suggested_before_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE picture ADD CONSTRAINT FK_16DB4F89D364767A FOREIGN KEY (suggested_before_id) REFERENCES picture (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_16DB4F89D364767A ON picture (suggested_before_id)');
    }

    public function down(Schema $schema): void
    {
        // cette migration down() est générée automatiquement, à adapter si besoin
        $this->addSql('ALTER TABLE picture DROP FOREIGN KEY FK_16DB4F89D364767A');
        $this->addSql('DROP INDEX IDX_16DB4F89D364767A ON picture');
        $this->addSql('ALTER TABLE picture DROP suggested_before_id');
    }
}
