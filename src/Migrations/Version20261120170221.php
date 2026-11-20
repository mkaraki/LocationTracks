<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120170221 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index location points by source and recorded time for Timeline deduplication';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_location_point_source_recorded_at ON location_point (source_id, recorded_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->platform->getDropIndexSQL('idx_location_point_source_recorded_at', 'location_point'));
    }
}
