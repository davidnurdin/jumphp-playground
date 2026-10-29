<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261029160633 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Comments, ideas and issues on the games; private notes and todo lists of the projects (MCP)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE comment (id INT AUTO_INCREMENT NOT NULL, approved TINYINT NOT NULL, resolved TINYINT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, body LONGTEXT NOT NULL, name VARCHAR(40) DEFAULT NULL, visitor VARCHAR(40) DEFAULT NULL, ip_hash VARCHAR(64) NOT NULL, kind VARCHAR(10) DEFAULT \'comment\' NOT NULL, project_id INT NOT NULL, author_id INT DEFAULT NULL, INDEX IDX_9474526C166D1F9C3BC4BCD97C57D81D8B8E8428 (project_id, kind, approved, created_at), INDEX IDX_9474526CC2DF3E378B8E8428 (ip_hash, created_at), INDEX IDX_9474526C166D1F9C (project_id), INDEX IDX_9474526CF675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE note (id INT AUTO_INCREMENT NOT NULL, content LONGTEXT NOT NULL, updated_at DATETIME NOT NULL, name VARCHAR(60) NOT NULL, project_id INT NOT NULL, UNIQUE INDEX UNIQ_CFBDFA14166D1F9C5E237E06 (project_id, name), INDEX IDX_CFBDFA14166D1F9C (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE todo (id INT AUTO_INCREMENT NOT NULL, done_at DATETIME DEFAULT NULL, done TINYINT NOT NULL, created_at DATETIME NOT NULL, text VARCHAR(500) NOT NULL, project_id INT NOT NULL, INDEX IDX_5A0EB6A0166D1F9C102DE0AB (project_id, done), INDEX IDX_5A0EB6A0166D1F9C (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526C166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526CF675F31B FOREIGN KEY (author_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE note ADD CONSTRAINT FK_CFBDFA14166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE todo ADD CONSTRAINT FK_5A0EB6A0166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project ADD comment_count INT DEFAULT 0 NOT NULL, ADD idea_count INT DEFAULT 0 NOT NULL, ADD issue_count INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE comment DROP FOREIGN KEY FK_9474526C166D1F9C');
        $this->addSql('ALTER TABLE comment DROP FOREIGN KEY FK_9474526CF675F31B');
        $this->addSql('ALTER TABLE note DROP FOREIGN KEY FK_CFBDFA14166D1F9C');
        $this->addSql('ALTER TABLE todo DROP FOREIGN KEY FK_5A0EB6A0166D1F9C');
        $this->addSql('DROP TABLE comment');
        $this->addSql('DROP TABLE note');
        $this->addSql('DROP TABLE todo');
        $this->addSql('ALTER TABLE project DROP comment_count, DROP idea_count, DROP issue_count');
    }
}
