<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261029174228 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE sandbox_share (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(16) NOT NULL, visitor VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME DEFAULT NULL, views INT NOT NULL, author VARCHAR(40) NOT NULL, title VARCHAR(80) NOT NULL, project_id INT NOT NULL, user_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_11363A8777153098 (code), UNIQUE INDEX UNIQ_11363A87CAE5E19F (visitor), INDEX IDX_11363A87F9D83E2 (expires_at), INDEX IDX_11363A87166D1F9C (project_id), INDEX IDX_11363A87A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE sandbox_share ADD CONSTRAINT FK_11363A87166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sandbox_share ADD CONSTRAINT FK_11363A87A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE app_user ADD notifications_seen_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sandbox_share DROP FOREIGN KEY FK_11363A87166D1F9C');
        $this->addSql('ALTER TABLE sandbox_share DROP FOREIGN KEY FK_11363A87A76ED395');
        $this->addSql('DROP TABLE sandbox_share');
        $this->addSql('ALTER TABLE app_user DROP notifications_seen_at');
    }
}
