<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** The playground's tables: users, categories, projects, drafts, the worker's queue. */
final class Version20261028223003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The playground tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_user (id INT AUTO_INCREMENT NOT NULL, github_id INT NOT NULL, login VARCHAR(39) NOT NULL, name VARCHAR(255) DEFAULT NULL, avatar_url VARCHAR(255) DEFAULT NULL, github_token LONGTEXT DEFAULT NULL, github_scopes VARCHAR(255) NOT NULL, mcp_token_hash VARCHAR(64) DEFAULT NULL, mcp_token_hint VARCHAR(16) DEFAULT NULL, roles JSON NOT NULL, created_at DATETIME NOT NULL, last_login_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_88BDF3E9D4327649 (github_id), UNIQUE INDEX UNIQ_88BDF3E9C69FA79F (mcp_token_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE category (id INT AUTO_INCREMENT NOT NULL, slug VARCHAR(40) NOT NULL, name VARCHAR(80) NOT NULL, description VARCHAR(255) NOT NULL, position INT NOT NULL, UNIQUE INDEX UNIQ_64C19C1989D9B62 (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE draft (id INT AUTO_INCREMENT NOT NULL, content LONGBLOB DEFAULT NULL, deleted TINYINT NOT NULL, updated_at DATETIME NOT NULL, path VARCHAR(255) NOT NULL, user_id INT NOT NULL, project_id INT NOT NULL, UNIQUE INDEX UNIQ_467C9694A76ED395166D1F9CB548B0F (user_id, project_id, path), INDEX IDX_467C9694A76ED395 (user_id), INDEX IDX_467C9694166D1F9C (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE project (id INT AUTO_INCREMENT NOT NULL, slug VARCHAR(40) NOT NULL, title VARCHAR(80) NOT NULL, description LONGTEXT NOT NULL, brief LONGTEXT NOT NULL, repo_full_name VARCHAR(140) NOT NULL, default_branch VARCHAR(100) NOT NULL, game_dir VARCHAR(120) NOT NULL, status VARCHAR(20) NOT NULL, status_message LONGTEXT NOT NULL, webhook_id INT DEFAULT NULL, webhook_secret VARCHAR(64) NOT NULL, published_sha VARCHAR(40) DEFAULT NULL, published_at DATETIME DEFAULT NULL, engine_version VARCHAR(20) DEFAULT NULL, game_info JSON NOT NULL, site JSON DEFAULT NULL, has_thumbnail TINYINT NOT NULL, listed TINYINT NOT NULL, plays INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, owner_id INT NOT NULL, category_id INT NOT NULL, fork_of_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_2FB3D0EE989D9B62 (slug), INDEX IDX_2FB3D0EE22F18EE3E0D4FDE1 (listed, published_at), INDEX IDX_2FB3D0EE7E3C61F9 (owner_id), INDEX IDX_2FB3D0EE12469DE2 (category_id), INDEX IDX_2FB3D0EEBA9A3285 (fork_of_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE draft ADD CONSTRAINT FK_467C9694A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE draft ADD CONSTRAINT FK_467C9694166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project ADD CONSTRAINT FK_2FB3D0EE7E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project ADD CONSTRAINT FK_2FB3D0EE12469DE2 FOREIGN KEY (category_id) REFERENCES category (id)');
        $this->addSql('ALTER TABLE project ADD CONSTRAINT FK_2FB3D0EEBA9A3285 FOREIGN KEY (fork_of_id) REFERENCES project (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE draft DROP FOREIGN KEY FK_467C9694A76ED395');
        $this->addSql('ALTER TABLE draft DROP FOREIGN KEY FK_467C9694166D1F9C');
        $this->addSql('ALTER TABLE project DROP FOREIGN KEY FK_2FB3D0EE7E3C61F9');
        $this->addSql('ALTER TABLE project DROP FOREIGN KEY FK_2FB3D0EE12469DE2');
        $this->addSql('ALTER TABLE project DROP FOREIGN KEY FK_2FB3D0EEBA9A3285');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE category');
        $this->addSql('DROP TABLE draft');
        $this->addSql('DROP TABLE project');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
