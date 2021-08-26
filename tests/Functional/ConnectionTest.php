<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ConnectionException;
use Doctrine\DBAL\Driver\AbstractSQLServerDriver;
use Doctrine\DBAL\Driver\IBMDB2;
use Doctrine\DBAL\Driver\PDO\Connection as PDOConnection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\DB2Platform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Tests\FunctionalTestCase;
use Doctrine\DBAL\Tests\TestUtil;
use Error;
use PDO;
use Throwable;

use function file_exists;
use function unlink;

class ConnectionTest extends FunctionalTestCase
{
    private const TABLE = 'connection_test';

    protected function tearDown(): void
    {
        if (file_exists('/tmp/test_nesting.sqlite')) {
            unlink('/tmp/test_nesting.sqlite');
        }

        $this->markConnectionNotReusable();

        parent::tearDown();
    }

    public function testCommitWithRollbackOnlyThrowsException(): void
    {
        $this->connection->beginTransaction();
        $this->connection->setRollbackOnly();

        $this->expectException(ConnectionException::class);
        $this->connection->commit();
    }

    public function testTransactionNestingBehavior(): void
    {
        $this->createTestTable();

        try {
            $this->connection->beginTransaction();
            self::assertSame(1, $this->connection->getTransactionNestingLevel());

            try {
                $this->connection->beginTransaction();
                self::assertSame(2, $this->connection->getTransactionNestingLevel());

                $this->connection->insert(self::TABLE, ['id' => 1]);
                self::fail('Expected exception to be thrown because of the unique constraint.');
            } catch (Throwable $e) {
                $this->assertIsUniqueConstraintException($e);
                $this->connection->rollBack();
                self::assertSame(1, $this->connection->getTransactionNestingLevel());
            }

            self::assertTrue($this->connection->isRollbackOnly());

            $this->connection->commit(); // should throw exception
            self::fail('Transaction commit after failed nested transaction should fail.');
        } catch (ConnectionException $e) {
            self::assertSame(1, $this->connection->getTransactionNestingLevel());
            $this->connection->rollBack();
            self::assertSame(0, $this->connection->getTransactionNestingLevel());
        }

        $this->connection->beginTransaction();
        $this->connection->close();
        $this->connection->beginTransaction();
        self::assertEquals(1, $this->connection->getTransactionNestingLevel());
    }

    public function testTransactionNestingLevelIsResetOnReconnect(): void
    {
        if ($this->connection->getDatabasePlatform() instanceof SqlitePlatform) {
            $params           = $this->connection->getParams();
            $params['memory'] = false;
            $params['path']   = '/tmp/test_nesting.sqlite';

            $connection = DriverManager::getConnection(
                $params,
                $this->connection->getConfiguration(),
                $this->connection->getEventManager()
            );
        } else {
            $connection = $this->connection;
        }

        $connection->executeQuery('CREATE TABLE test_nesting(test int not null)');

        $this->connection->beginTransaction();
        $this->connection->beginTransaction();
        $connection->close(); // connection closed in runtime (for example if lost or another application logic)

        $connection->beginTransaction();
        $connection->executeQuery('insert into test_nesting values (33)');
        $connection->rollBack();

        self::assertEquals(0, $connection->fetchOne('select count(*) from test_nesting'));
    }

    public function testTransactionNestingBehaviorWithSavepoints(): void
    {
        if (! $this->connection->getDatabasePlatform()->supportsSavepoints()) {
            self::markTestSkipped('This test requires the platform to support savepoints.');
        }

        $this->createTestTable();

        $this->connection->setNestTransactionsWithSavepoints(true);
        try {
            $this->connection->beginTransaction();
            self::assertSame(1, $this->connection->getTransactionNestingLevel());

            try {
                $this->connection->beginTransaction();
                self::assertSame(2, $this->connection->getTransactionNestingLevel());
                $this->connection->beginTransaction();
                self::assertSame(3, $this->connection->getTransactionNestingLevel());
                $this->connection->commit();
                self::assertSame(2, $this->connection->getTransactionNestingLevel());

                $this->connection->insert(self::TABLE, ['id' => 1]);
                self::fail('Expected exception to be thrown because of the unique constraint.');
            } catch (Throwable $e) {
                $this->assertIsUniqueConstraintException($e);
                $this->connection->rollBack();
                self::assertSame(1, $this->connection->getTransactionNestingLevel());
            }

            self::assertFalse($this->connection->isRollbackOnly());
            try {
                $this->connection->setNestTransactionsWithSavepoints(false);
                self::fail('Should not be able to disable savepoints in usage inside a nested open transaction.');
            } catch (ConnectionException $e) {
                self::assertTrue($this->connection->getNestTransactionsWithSavepoints());
            }

            $this->connection->commit(); // should not throw exception
        } catch (ConnectionException $e) {
            self::fail('Transaction commit after failed nested transaction should not fail when using savepoints.');
        }
    }

    public function testTransactionNestingBehaviorCantBeChangedInActiveTransaction(): void
    {
        if (! $this->connection->getDatabasePlatform()->supportsSavepoints()) {
            self::markTestSkipped('This test requires the platform to support savepoints.');
        }

        $this->connection->beginTransaction();
        $this->expectException(ConnectionException::class);
        $this->connection->setNestTransactionsWithSavepoints(true);
    }

    public function testTransactionIsInactiveAfterConnectionClose(): void
    {
        $this->connection->beginTransaction();
        $this->connection->close();

        self::assertFalse($this->connection->isTransactionActive());
    }

    public function testSetNestedTransactionsThroughSavepointsNotSupportedThrowsException(): void
    {
        if ($this->connection->getDatabasePlatform()->supportsSavepoints()) {
            self::markTestSkipped('This test requires the platform not to support savepoints.');
        }

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Savepoints are not supported by this driver.');

        $this->connection->setNestTransactionsWithSavepoints(true);
    }

    public function testCreateSavepointsNotSupportedThrowsException(): void
    {
        if ($this->connection->getDatabasePlatform()->supportsSavepoints()) {
            self::markTestSkipped('This test requires the platform not to support savepoints.');
        }

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Savepoints are not supported by this driver.');

        $this->connection->createSavepoint('foo');
    }

    public function testReleaseSavepointsNotSupportedThrowsException(): void
    {
        if ($this->connection->getDatabasePlatform()->supportsSavepoints()) {
            self::markTestSkipped('This test requires the platform not to support savepoints.');
        }

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Savepoints are not supported by this driver.');

        $this->connection->releaseSavepoint('foo');
    }

    public function testRollbackSavepointsNotSupportedThrowsException(): void
    {
        if ($this->connection->getDatabasePlatform()->supportsSavepoints()) {
            self::markTestSkipped('This test requires the platform not to support savepoints.');
        }

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Savepoints are not supported by this driver.');

        $this->connection->rollbackSavepoint('foo');
    }

    public function testTransactionBehaviorWithRollback(): void
    {
        $this->createTestTable();

        try {
            $this->connection->beginTransaction();
            self::assertSame(1, $this->connection->getTransactionNestingLevel());

            $this->connection->insert(self::TABLE, ['id' => 1]);
            self::fail('Expected exception to be thrown because of the unique constraint.');
        } catch (Throwable $e) {
            $this->assertIsUniqueConstraintException($e);
            self::assertSame(1, $this->connection->getTransactionNestingLevel());
            $this->connection->rollBack();
            self::assertSame(0, $this->connection->getTransactionNestingLevel());
        }
    }

    public function testTransactionBehaviour(): void
    {
        $this->createTestTable();

        $this->connection->beginTransaction();
        self::assertSame(1, $this->connection->getTransactionNestingLevel());
        $this->connection->insert(self::TABLE, ['id' => 2]);
        $this->connection->commit();
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
    }

    public function testTransactionalWithException(): void
    {
        $this->createTestTable();

        try {
            $this->connection->transactional(static function (Connection $connection): void {
                $connection->insert(self::TABLE, ['id' => 1]);
            });
            self::fail('Expected exception to be thrown because of the unique constraint.');
        } catch (Throwable $e) {
            $this->assertIsUniqueConstraintException($e);
            self::assertSame(0, $this->connection->getTransactionNestingLevel());
        }
    }

    public function testTransactionalWithThrowable(): void
    {
        try {
            $this->connection->transactional(static function (Connection $conn): void {
                $conn->executeQuery($conn->getDatabasePlatform()->getDummySelectSQL());

                throw new Error('Ooops!');
            });
            self::fail('Expected exception');
        } catch (Error $expected) {
            self::assertEquals(0, $this->connection->getTransactionNestingLevel());
        }
    }

    public function testTransactional(): void
    {
        $this->createTestTable();

        $res = $this->connection->transactional(static function (Connection $connection): void {
            $connection->insert(self::TABLE, ['id' => 2]);
        });

        self::assertNull($res);
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
    }

    public function testTransactionalReturnValue(): void
    {
        $res = $this->connection->transactional(static function (): int {
            return 42;
        });

        self::assertEquals(42, $res);
    }

    public function testConnectWithoutExplicitDatabaseName(): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof OraclePlatform || $platform instanceof DB2Platform) {
            self::markTestSkipped('Platform does not support connecting without database name.');
        }

        $params = $this->connection->getParams();
        unset($params['dbname']);

        $connection = DriverManager::getConnection(
            $params,
            $this->connection->getConfiguration(),
            $this->connection->getEventManager()
        );

        $connection->connect();

        self::assertTrue($connection->isConnected());

        $connection->close();
    }

    public function testDeterminesDatabasePlatformWhenConnectingToNonExistentDatabase(): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof OraclePlatform || $platform instanceof DB2Platform) {
            self::markTestSkipped('Platform does not support connecting without database name.');
        }

        $params = $this->connection->getParams();

        $params['dbname'] = 'foo_bar';

        $connection = DriverManager::getConnection(
            $params,
            $this->connection->getConfiguration(),
            $this->connection->getEventManager()
        );

        self::assertFalse($connection->isConnected());
        self::assertSame($params, $connection->getParams());

        $connection->close();
    }

    public function testPersistentConnection(): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if (
            $platform instanceof SqlitePlatform
            || $platform instanceof SQLServerPlatform
        ) {
            self::markTestSkipped('The platform does not support persistent connections');
        }

        $params               = TestUtil::getConnectionParams();
        $params['persistent'] = true;

        $connection       = DriverManager::getConnection($params);
        $driverConnection = $connection->getWrappedConnection();

        if (! $driverConnection instanceof PDOConnection) {
            self::markTestSkipped('Unable to test if the connection is persistent');
        }

        $pdo = $driverConnection->getWrappedConnection();

        self::assertTrue($pdo->getAttribute(PDO::ATTR_PERSISTENT));
    }

    private function createTestTable(): void
    {
        $table = new Table(self::TABLE);
        $table->addColumn('id', 'integer');
        $table->setPrimaryKey(['id']);

        $this->connection->createSchemaManager()->dropAndCreateTable($table);

        $this->connection->insert(self::TABLE, ['id' => 1]);
    }

    private function assertIsUniqueConstraintException(Throwable $exception): void
    {
        if ($this->connection->getDriver() instanceof IBMDB2\Driver) {
            // The IBM DB2 driver currently doesn't instantiate specialized exceptions

            return;
        }

        if ($this->connection->getDriver() instanceof AbstractSQLServerDriver) {
            // The SQL Server drivers currently don't instantiate specialized exceptions

            return;
        }

        self::assertInstanceOf(UniqueConstraintViolationException::class, $exception);
    }
}
