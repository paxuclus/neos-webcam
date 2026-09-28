<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
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
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            "Migration can only be executed safely on PostgreSQL."
        );

        $this->addSql('CREATE TABLE lala_webcam_domain_model_snapshot (persistence_object_identifier VARCHAR(40) NOT NULL, resource VARCHAR(40) NOT NULL, contentrepositoryid VARCHAR(16) NOT NULL, nodeaggregateid VARCHAR(64) NOT NULL, width INT NOT NULL, height INT NOT NULL, createdat TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(persistence_object_identifier))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_382ED825BC91F416 ON lala_webcam_domain_model_snapshot (resource)');
        $this->addSql('CREATE INDEX webcam ON lala_webcam_domain_model_snapshot (contentrepositoryid, nodeaggregateid)');
        $this->addSql('COMMENT ON COLUMN lala_webcam_domain_model_snapshot.createdat IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE lala_webcam_domain_model_snapshot ADD CONSTRAINT FK_382ED825BC91F416 FOREIGN KEY (resource) REFERENCES neos_flow_resourcemanagement_persistentresource (persistence_object_identifier) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            "Migration can only be executed safely on PostgreSQL."
        );

        $this->addSql('DROP TABLE lala_webcam_domain_model_snapshot');
    }
}
