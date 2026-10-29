<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261029035636 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drafts of the sandbox visitors (no account)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE draft ADD visitor VARCHAR(32) DEFAULT NULL, CHANGE user_id user_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_467C9694CAE5E19F43625D9F ON draft (visitor, updated_at)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_467C9694CAE5E19F166D1F9CB548B0F ON draft (visitor, project_id, path)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX IDX_467C9694CAE5E19F43625D9F ON draft');
        $this->addSql('DROP INDEX UNIQ_467C9694CAE5E19F166D1F9CB548B0F ON draft');
        $this->addSql('ALTER TABLE draft DROP visitor, CHANGE user_id user_id INT NOT NULL');
    }
}
