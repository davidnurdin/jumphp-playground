<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261029204449 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE game_day (id INT AUTO_INCREMENT NOT NULL, day DATE NOT NULL, visits INT DEFAULT 0 NOT NULL, plays INT DEFAULT 0 NOT NULL, launches INT DEFAULT 0 NOT NULL, project_id INT NOT NULL, UNIQUE INDEX UNIQ_FEFA3A55166D1F9CE5A02990 (project_id, day), INDEX IDX_FEFA3A55166D1F9C (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE game_day ADD CONSTRAINT FK_FEFA3A55166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game_day DROP FOREIGN KEY FK_FEFA3A55166D1F9C');
        $this->addSql('DROP TABLE game_day');
    }
}
