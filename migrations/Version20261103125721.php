<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration générée automatiquement : à adapter si besoin !
 */
final class Version20261103125721 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quiz du jour : quiz quotidien, ses 5 places, parties et manches des joueurs';
    }

    public function up(Schema $schema): void
    {
        // cette migration up() est générée automatiquement, à adapter si besoin
        $this->addSql('CREATE TABLE daily_quiz (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_daily_quiz_date (date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE daily_quiz_memory (id INT AUTO_INCREMENT NOT NULL, position SMALLINT NOT NULL, quiz_id INT NOT NULL, memory_id INT DEFAULT NULL, INDEX IDX_D36F1773853CD175 (quiz_id), INDEX IDX_D36F1773CCC80CB3 (memory_id), UNIQUE INDEX uniq_daily_quiz_memory_position (quiz_id, position), UNIQUE INDEX uniq_daily_quiz_memory_memory (quiz_id, memory_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE quiz_game (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, status VARCHAR(20) NOT NULL, total_score INT DEFAULT 0 NOT NULL, started_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, user_id INT NOT NULL, INDEX IDX_E9DACDD7A76ED395 (user_id), INDEX idx_quiz_game_date_status (date, status), UNIQUE INDEX uniq_quiz_game_user_date (user_id, date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE quiz_round (id INT AUTO_INCREMENT NOT NULL, position SMALLINT NOT NULL, memory_title VARCHAR(255) NOT NULL, picture_name VARCHAR(255) NOT NULL, true_lat DOUBLE PRECISION NOT NULL, true_lng DOUBLE PRECISION NOT NULL, true_year INT NOT NULL, answer_lat DOUBLE PRECISION DEFAULT NULL, answer_lng DOUBLE PRECISION DEFAULT NULL, answer_year INT DEFAULT NULL, distance_km DOUBLE PRECISION DEFAULT NULL, year_diff INT DEFAULT NULL, location_score INT DEFAULT NULL, year_score INT DEFAULT NULL, score INT DEFAULT NULL, answered_at DATETIME DEFAULT NULL, game_id INT NOT NULL, memory_id INT DEFAULT NULL, INDEX IDX_399D93B4E48FD905 (game_id), INDEX IDX_399D93B4CCC80CB3 (memory_id), UNIQUE INDEX uniq_quiz_round_game_position (game_id, position), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE daily_quiz_memory ADD CONSTRAINT FK_D36F1773853CD175 FOREIGN KEY (quiz_id) REFERENCES daily_quiz (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE daily_quiz_memory ADD CONSTRAINT FK_D36F1773CCC80CB3 FOREIGN KEY (memory_id) REFERENCES memory (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE quiz_game ADD CONSTRAINT FK_E9DACDD7A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quiz_round ADD CONSTRAINT FK_399D93B4E48FD905 FOREIGN KEY (game_id) REFERENCES quiz_game (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quiz_round ADD CONSTRAINT FK_399D93B4CCC80CB3 FOREIGN KEY (memory_id) REFERENCES memory (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // cette migration down() est générée automatiquement, à adapter si besoin
        $this->addSql('ALTER TABLE daily_quiz_memory DROP FOREIGN KEY FK_D36F1773853CD175');
        $this->addSql('ALTER TABLE daily_quiz_memory DROP FOREIGN KEY FK_D36F1773CCC80CB3');
        $this->addSql('ALTER TABLE quiz_game DROP FOREIGN KEY FK_E9DACDD7A76ED395');
        $this->addSql('ALTER TABLE quiz_round DROP FOREIGN KEY FK_399D93B4E48FD905');
        $this->addSql('ALTER TABLE quiz_round DROP FOREIGN KEY FK_399D93B4CCC80CB3');
        $this->addSql('DROP TABLE daily_quiz');
        $this->addSql('DROP TABLE daily_quiz_memory');
        $this->addSql('DROP TABLE quiz_game');
        $this->addSql('DROP TABLE quiz_round');
    }
}
