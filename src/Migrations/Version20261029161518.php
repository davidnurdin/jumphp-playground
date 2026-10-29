<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261029161518 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The runs of the games\' PHPUnit tests (tester)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE test_run (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(10) NOT NULL, output LONGTEXT NOT NULL, summary JSON NOT NULL, created_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, sha VARCHAR(40) NOT NULL, project_id INT NOT NULL, INDEX IDX_290B7807166D1F9C8B8E8428 (project_id, created_at), INDEX IDX_290B7807166D1F9C (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE test_run ADD CONSTRAINT FK_290B7807166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE test_run DROP FOREIGN KEY FK_290B7807166D1F9C');
        $this->addSql('DROP TABLE test_run');
    }
}
