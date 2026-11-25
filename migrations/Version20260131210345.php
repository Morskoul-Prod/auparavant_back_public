<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration générée automatiquement : à adapter si besoin !
 */
final class Version20260131210345 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // cette migration up() est générée automatiquement, à adapter si besoin
        $this->addSql('CREATE TABLE location (id INT AUTO_INCREMENT NOT NULL, area VARCHAR(50) NOT NULL, department VARCHAR(30) NOT NULL, district VARCHAR(100) DEFAULT NULL, street VARCHAR(50) NOT NULL, city VARCHAR(50) NOT NULL, zipcode INT NOT NULL, latitude NUMERIC(10, 8) NOT NULL, longitude NUMERIC(11, 8) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE memory (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(50) NOT NULL, content LONGTEXT NOT NULL, picture_date DATETIME DEFAULT NULL, year INT DEFAULT NULL, published_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, location_id INT NOT NULL, user_id INT NOT NULL, place_id INT NOT NULL, INDEX IDX_EA6D343564D218E (location_id), INDEX IDX_EA6D3435A76ED395 (user_id), INDEX IDX_EA6D3435DA6A219 (place_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE picture (id INT AUTO_INCREMENT NOT NULL, image_name VARCHAR(255) DEFAULT NULL, position INT DEFAULT 0 NOT NULL, type VARCHAR(20) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, memory_id INT NOT NULL, INDEX IDX_16DB4F89CCC80CB3 (memory_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE place (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(30) DEFAULT NULL, type VARCHAR(30) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, location_id INT NOT NULL, INDEX IDX_741D53CD64D218E (location_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reset_password_request (id INT AUTO_INCREMENT NOT NULL, selector VARCHAR(20) NOT NULL, hashed_token VARCHAR(100) NOT NULL, requested_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_7CE748AA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user (id INT AUTO_INCREMENT NOT NULL, firstname VARCHAR(30) NOT NULL, lastname VARCHAR(30) NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, roles JSON NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_8D93D649E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0 (queue_name), INDEX IDX_75EA56E0E3BD61CE (available_at), INDEX IDX_75EA56E016BA31DB (delivered_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE memory ADD CONSTRAINT FK_EA6D343564D218E FOREIGN KEY (location_id) REFERENCES location (id)');
        $this->addSql('ALTER TABLE memory ADD CONSTRAINT FK_EA6D3435A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE memory ADD CONSTRAINT FK_EA6D3435DA6A219 FOREIGN KEY (place_id) REFERENCES place (id)');
        $this->addSql('ALTER TABLE picture ADD CONSTRAINT FK_16DB4F89CCC80CB3 FOREIGN KEY (memory_id) REFERENCES memory (id)');
        $this->addSql('ALTER TABLE place ADD CONSTRAINT FK_741D53CD64D218E FOREIGN KEY (location_id) REFERENCES location (id)');
        $this->addSql('ALTER TABLE reset_password_request ADD CONSTRAINT FK_7CE748AA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        // cette migration down() est générée automatiquement, à adapter si besoin
        $this->addSql('ALTER TABLE memory DROP FOREIGN KEY FK_EA6D343564D218E');
        $this->addSql('ALTER TABLE memory DROP FOREIGN KEY FK_EA6D3435A76ED395');
        $this->addSql('ALTER TABLE memory DROP FOREIGN KEY FK_EA6D3435DA6A219');
        $this->addSql('ALTER TABLE picture DROP FOREIGN KEY FK_16DB4F89CCC80CB3');
        $this->addSql('ALTER TABLE place DROP FOREIGN KEY FK_741D53CD64D218E');
        $this->addSql('ALTER TABLE reset_password_request DROP FOREIGN KEY FK_7CE748AA76ED395');
        $this->addSql('DROP TABLE location');
        $this->addSql('DROP TABLE memory');
        $this->addSql('DROP TABLE picture');
        $this->addSql('DROP TABLE place');
        $this->addSql('DROP TABLE reset_password_request');
        $this->addSql('DROP TABLE user');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
