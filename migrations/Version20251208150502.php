<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration générée automatiquement : à adapter si besoin !
 */
final class Version20251208150502 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // cette migration up() est générée automatiquement, à adapter si besoin
        $this->addSql('CREATE TABLE reset_password_request (id INT AUTO_INCREMENT NOT NULL, selector VARCHAR(20) NOT NULL, hashed_token VARCHAR(100) NOT NULL, requested_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_7CE748AA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE reset_password_request ADD CONSTRAINT FK_7CE748AA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE location CHANGE area area VARCHAR(50) NOT NULL, CHANGE department department VARCHAR(30) NOT NULL, CHANGE district district VARCHAR(20) DEFAULT NULL, CHANGE street street VARCHAR(50) NOT NULL, CHANGE city city VARCHAR(50) NOT NULL, CHANGE zipcode zipcode INT NOT NULL, CHANGE latitude latitude NUMERIC(10, 8) NOT NULL, CHANGE longitude longitude NUMERIC(11, 8) NOT NULL, CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE memory ADD location_id INT NOT NULL, ADD user_id INT NOT NULL, ADD place_id INT NOT NULL, CHANGE title title VARCHAR(50) NOT NULL, CHANGE content content LONGTEXT NOT NULL, CHANGE picture_date picture_date DATETIME NOT NULL, CHANGE main_picture main_picture VARCHAR(2000) NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE update_at updated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE memory ADD CONSTRAINT FK_EA6D343564D218E FOREIGN KEY (location_id) REFERENCES location (id)');
        $this->addSql('ALTER TABLE memory ADD CONSTRAINT FK_EA6D3435A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE memory ADD CONSTRAINT FK_EA6D3435DA6A219 FOREIGN KEY (place_id) REFERENCES place (id)');
        $this->addSql('CREATE INDEX IDX_EA6D343564D218E ON memory (location_id)');
        $this->addSql('CREATE INDEX IDX_EA6D3435A76ED395 ON memory (user_id)');
        $this->addSql('CREATE INDEX IDX_EA6D3435DA6A219 ON memory (place_id)');
        $this->addSql('ALTER TABLE picture ADD memory_id INT NOT NULL, CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE picture ADD CONSTRAINT FK_16DB4F89CCC80CB3 FOREIGN KEY (memory_id) REFERENCES memory (id)');
        $this->addSql('CREATE INDEX IDX_16DB4F89CCC80CB3 ON picture (memory_id)');
        $this->addSql('ALTER TABLE place ADD location_id INT NOT NULL, CHANGE name name VARCHAR(30) DEFAULT NULL, CHANGE type type VARCHAR(30) NOT NULL, CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE place ADD CONSTRAINT FK_741D53CD64D218E FOREIGN KEY (location_id) REFERENCES location (id)');
        $this->addSql('CREATE INDEX IDX_741D53CD64D218E ON place (location_id)');
        $this->addSql('ALTER TABLE user CHANGE firstname firstname VARCHAR(30) NOT NULL, CHANGE lastname lastname VARCHAR(30) NOT NULL');
        $this->addSql('ALTER TABLE user RENAME INDEX uniq_identifier_email TO UNIQ_8D93D649E7927C74');
    }

    public function down(Schema $schema): void
    {
        // cette migration down() est générée automatiquement, à adapter si besoin
        $this->addSql('ALTER TABLE reset_password_request DROP FOREIGN KEY FK_7CE748AA76ED395');
        $this->addSql('DROP TABLE reset_password_request');
        $this->addSql('ALTER TABLE location CHANGE area area VARCHAR(50) DEFAULT NULL, CHANGE department department VARCHAR(50) DEFAULT NULL, CHANGE district district VARCHAR(50) DEFAULT NULL, CHANGE street street VARCHAR(100) DEFAULT NULL, CHANGE city city VARCHAR(50) DEFAULT NULL, CHANGE zipcode zipcode INT DEFAULT NULL, CHANGE latitude latitude NUMERIC(10, 8) DEFAULT NULL, CHANGE longitude longitude NUMERIC(11, 8) DEFAULT NULL, CHANGE created_at created_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE memory DROP FOREIGN KEY FK_EA6D343564D218E');
        $this->addSql('ALTER TABLE memory DROP FOREIGN KEY FK_EA6D3435A76ED395');
        $this->addSql('ALTER TABLE memory DROP FOREIGN KEY FK_EA6D3435DA6A219');
        $this->addSql('DROP INDEX IDX_EA6D343564D218E ON memory');
        $this->addSql('DROP INDEX IDX_EA6D3435A76ED395 ON memory');
        $this->addSql('DROP INDEX IDX_EA6D3435DA6A219 ON memory');
        $this->addSql('ALTER TABLE memory DROP location_id, DROP user_id, DROP place_id, CHANGE title title VARCHAR(255) DEFAULT NULL, CHANGE content content LONGTEXT DEFAULT NULL, CHANGE picture_date picture_date DATETIME DEFAULT NULL, CHANGE main_picture main_picture VARCHAR(2000) DEFAULT NULL, CHANGE created_at created_at DATETIME DEFAULT NULL, CHANGE updated_at update_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE picture DROP FOREIGN KEY FK_16DB4F89CCC80CB3');
        $this->addSql('DROP INDEX IDX_16DB4F89CCC80CB3 ON picture');
        $this->addSql('ALTER TABLE picture DROP memory_id, CHANGE created_at created_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE place DROP FOREIGN KEY FK_741D53CD64D218E');
        $this->addSql('DROP INDEX IDX_741D53CD64D218E ON place');
        $this->addSql('ALTER TABLE place DROP location_id, CHANGE name name VARCHAR(255) DEFAULT NULL, CHANGE type type VARCHAR(50) DEFAULT NULL, CHANGE created_at created_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE user CHANGE firstname firstname VARCHAR(100) NOT NULL, CHANGE lastname lastname VARCHAR(100) NOT NULL');
        $this->addSql('ALTER TABLE user RENAME INDEX uniq_8d93d649e7927c74 TO UNIQ_IDENTIFIER_EMAIL');
    }
}
