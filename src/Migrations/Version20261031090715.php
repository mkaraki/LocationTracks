<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031090715 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the initial location tracking schema for MariaDB 10.6';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE location_point (id INT AUTO_INCREMENT NOT NULL, recorded_at DATETIME NOT NULL, latitude DOUBLE PRECISION NOT NULL, longitude DOUBLE PRECISION NOT NULL, accuracy_m DOUBLE PRECISION DEFAULT NULL, altitude_m DOUBLE PRECISION DEFAULT NULL, speed_kmh DOUBLE PRECISION DEFAULT NULL, heading_deg DOUBLE PRECISION DEFAULT NULL, battery_percent INT DEFAULT NULL, metadata JSON DEFAULT NULL, created_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, source_id INT NOT NULL, raw_record_id INT NOT NULL, supersedes_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_C1F9B6017A7685A8 (supersedes_id), INDEX IDX_C1F9B601953C1C61 (source_id), INDEX IDX_C1F9B60152D9543D (raw_record_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE location_source (id INT AUTO_INCREMENT NOT NULL, source_type INT NOT NULL, name VARCHAR(255) NOT NULL, auth_username VARCHAR(255) DEFAULT NULL, auth_password_hash VARCHAR(255) DEFAULT NULL, owntracks_device_id VARCHAR(255) DEFAULT NULL, owntracks_tracker_id VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, user_id INT NOT NULL, INDEX IDX_14F8F771A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE raw_record (id INT AUTO_INCREMENT NOT NULL, record_type VARCHAR(64) NOT NULL, source_record_dedup_key VARCHAR(512) DEFAULT NULL, payload_hash VARBINARY(64) NOT NULL, payload LONGTEXT NOT NULL, recorded_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, parse_status VARCHAR(16) NOT NULL, parse_error VARCHAR(512) DEFAULT NULL, parsed_at DATETIME DEFAULT NULL, source_id INT NOT NULL, INDEX IDX_3D097D1953C1C61 (source_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(255) NOT NULL, password VARCHAR(255) NOT NULL, roles JSON NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE location_point ADD CONSTRAINT FK_C1F9B601953C1C61 FOREIGN KEY (source_id) REFERENCES location_source (id)');
        $this->addSql('ALTER TABLE location_point ADD CONSTRAINT FK_C1F9B60152D9543D FOREIGN KEY (raw_record_id) REFERENCES raw_record (id)');
        $this->addSql('ALTER TABLE location_point ADD CONSTRAINT FK_C1F9B6017A7685A8 FOREIGN KEY (supersedes_id) REFERENCES location_point (id)');
        $this->addSql('ALTER TABLE location_source ADD CONSTRAINT FK_14F8F771A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE raw_record ADD CONSTRAINT FK_3D097D1953C1C61 FOREIGN KEY (source_id) REFERENCES location_source (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE location_point DROP FOREIGN KEY FK_C1F9B601953C1C61');
        $this->addSql('ALTER TABLE location_point DROP FOREIGN KEY FK_C1F9B60152D9543D');
        $this->addSql('ALTER TABLE location_point DROP FOREIGN KEY FK_C1F9B6017A7685A8');
        $this->addSql('ALTER TABLE location_source DROP FOREIGN KEY FK_14F8F771A76ED395');
        $this->addSql('ALTER TABLE raw_record DROP FOREIGN KEY FK_3D097D1953C1C61');
        $this->addSql('DROP TABLE location_point');
        $this->addSql('DROP TABLE location_source');
        $this->addSql('DROP TABLE raw_record');
        $this->addSql('DROP TABLE user');
    }
}
