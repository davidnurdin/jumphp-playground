<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261028234033 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Marks of the games (rating), their sum and count on project';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE rating (id INT AUTO_INCREMENT NOT NULL, updated_at DATETIME NOT NULL, voter VARCHAR(40) NOT NULL, score SMALLINT NOT NULL, project_id INT NOT NULL, UNIQUE INDEX UNIQ_D8892622166D1F9C268C4A59 (project_id, voter), INDEX IDX_D8892622166D1F9C (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE rating ADD CONSTRAINT FK_D8892622166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project ADD rating_sum INT NOT NULL, ADD rating_count INT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE rating DROP FOREIGN KEY FK_D8892622166D1F9C');
        $this->addSql('DROP TABLE rating');
        $this->addSql('ALTER TABLE project DROP rating_sum, DROP rating_count');
    }
}
