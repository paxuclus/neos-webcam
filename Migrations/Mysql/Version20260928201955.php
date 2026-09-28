<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260928201955 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the table for webcam snapshots';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            "Migration can only be executed safely on MySQL or MariaDB."
        );

        $this->addSql('CREATE TABLE lala_webcam_domain_model_snapshot (persistence_object_identifier VARCHAR(40) NOT NULL, resource VARCHAR(40) NOT NULL, contentrepositoryid VARCHAR(16) NOT NULL, nodeaggregateid VARCHAR(64) NOT NULL, width INT NOT NULL, height INT NOT NULL, createdat DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_382ED825BC91F416 (resource), INDEX webcam (contentrepositoryid, nodeaggregateid), PRIMARY KEY(persistence_object_identifier)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE lala_webcam_domain_model_snapshot ADD CONSTRAINT FK_382ED825BC91F416 FOREIGN KEY (resource) REFERENCES neos_flow_resourcemanagement_persistentresource (persistence_object_identifier)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            "Migration can only be executed safely on MySQL or MariaDB."
        );

        $this->addSql('DROP TABLE lala_webcam_domain_model_snapshot');
    }
}
