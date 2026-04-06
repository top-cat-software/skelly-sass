<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Database\Migration;

use App\Infrastructure\Database\Migration\Version20260405000000;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Query\Query;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Version20260405000000Test extends TestCase
{
    #[Test]
    public function it_has_a_description(): void
    {
        $migration = $this->createMigration();

        self::assertNotEmpty($migration->getDescription());
        self::assertStringContainsString('health_check', $migration->getDescription());
    }

    #[Test]
    public function up_generates_create_table_sql(): void
    {
        $migration = $this->createMigration();
        $schema = $this->createMock(Schema::class);

        $migration->up($schema);

        $queries = $migration->getSql();

        self::assertNotEmpty($queries);
        self::assertContainsOnlyInstancesOf(Query::class, $queries);

        $sql = $queries[0]->getStatement();
        self::assertStringContainsString('CREATE TABLE health_check', $sql);
        self::assertStringContainsString('checked_at', $sql);
        self::assertStringContainsString('TIMESTAMP WITH TIME ZONE', $sql);
    }

    #[Test]
    public function down_generates_drop_table_sql(): void
    {
        $migration = $this->createMigration();
        $schema = $this->createMock(Schema::class);

        $migration->down($schema);

        $queries = $migration->getSql();

        self::assertNotEmpty($queries);
        self::assertStringContainsString('DROP TABLE', $queries[0]->getStatement());
    }

    private function createMigration(): Version20260405000000
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')
            ->willReturn(new PostgreSQLPlatform());

        return new Version20260405000000($connection, new \Psr\Log\NullLogger());
    }
}
