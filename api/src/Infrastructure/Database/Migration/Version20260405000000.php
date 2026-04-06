<?php

declare(strict_types=1);

namespace App\Infrastructure\Database\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial walking skeleton migration.
 *
 * Creates the health_check table used to verify database write access
 * from the health endpoint.
 */
final class Version20260405000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create health_check table for database write verification';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE health_check (
                id SERIAL PRIMARY KEY,
                checked_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS health_check');
    }
}
