<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Database;
use PDO;

class DatabaseTest extends TestCase
{
    public function testGetInstanceReturnsPDO(): void
    {
        $db = Database::getInstance();
        $this->assertInstanceOf(PDO::class, $db);
    }

    public function testGetInstanceReturnsSameInstance(): void
    {
        $db1 = Database::getInstance();
        $db2 = Database::getInstance();
        $this->assertSame($db1, $db2);
    }

    public function testDatabaseConnectionHasCorrectAttributes(): void
    {
        $db = Database::getInstance();

        // Test error mode
        $this->assertEquals(
            PDO::ERRMODE_EXCEPTION,
            $db->getAttribute(PDO::ATTR_ERRMODE)
        );

        // Test fetch mode
        $this->assertEquals(
            PDO::FETCH_ASSOC,
            $db->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE)
        );
    }

    public function testCanExecuteQuery(): void
    {
        $db = Database::getInstance();
        $stmt = $db->query('SELECT 1 as test');
        $this->assertNotFalse($stmt, 'Query failed');
        $result = $stmt->fetch();

        $this->assertEquals(['test' => 1], $result);
    }

    public function testCanPrepareStatement(): void
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT ? as test');

        $this->assertInstanceOf(\PDOStatement::class, $stmt);
    }
}
