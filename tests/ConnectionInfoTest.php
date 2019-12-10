<?php

namespace Datahouse\Libraries\Database\Tests;

use Datahouse\Libraries\Database\ConnectionInfo;
use Datahouse\Libraries\Database\Exceptions\UserError;

/**
 * Class ConnectionInfoTest
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class ConnectionInfoTest extends \PHPUnit_Framework_TestCase
{
    public function setUp()
    {
        putenv('MYSQL_TEST_HOST=somehost');
        putenv('MYSQL_TEST_PORT=4242');
        putenv('MYSQL_TEST_PORT_HIGH=42');
        putenv('MYSQL_TEST_PORT_LOW=42');
        putenv('MYSQL_TEST_DBNAME=projXYZ');
        putenv('SYSTEM_VARIANT=live');
    }

    public function tearDown()
    {
        putenv('MYSQL_TEST_HOST');
        putenv('MYSQL_TEST_PORT');
        putenv('MYSQL_TEST_PORT_HIGH');
        putenv('MYSQL_TEST_PORT_LOW');
        putenv('MYSQL_TEST_DBNAME');
        putenv('SYSTEM_VARIANT');
    }

    public function test()
    {
        $conn_info = new ConnectionInfo\Postgres();
        $conn_info->loadFromDef([
            'dbname' => 'testdb',
            'host' => 'host.example.com',
            'username' => 'donald',
            'password' => '1234',
        ]);

        self::assertTrue($conn_info->isDefaultPort());
        self::assertEquals(
            $conn_info->getDSN(),
            "pgsql:host=host.example.com;dbname=testdb"
        );

        self::assertEquals('host.example.com', $conn_info->getFqdn());
    }

    public function testLocalhost()
    {
        $conn_info = new ConnectionInfo\Postgres();
        $conn_info->loadFromDef([
            'dbname' => 'testdb',
            'username' => 'donald',
            'password' => '1234',
        ]);

        self::assertTrue($conn_info->isDefaultPort());
        self::assertEquals(
            $conn_info->getDSN(),
            "pgsql:host=localhost;dbname=testdb"
        );

        self::assertEquals('localhost', $conn_info->getFqdn());
    }

    public function testSimpleVarReplacement()
    {
        $conn_info = new ConnectionInfo\Postgres();
        $conn_info->loadFromDef([
            'type' => 'mysql',
            'host' => '$MYSQL_TEST_HOST',
            'port' => '${MYSQL_TEST_PORT}',
            'dbname' => '${MYSQL_TEST_DBNAME}_live',
            'username' => 'donald',
            'password' => '1234',
        ]);

        self::assertEquals(
            $conn_info->getDSN(),
            "pgsql:host=somehost;port=4242;dbname=projXYZ_live"
        );
    }

    public function testMultipleVarReplacement()
    {
        $conn_info = new ConnectionInfo\Postgres();
        $conn_info->loadFromDef([
            'type' => 'mysql',
            'host' => '$MYSQL_TEST_HOST.example.com',
            'port' => '${MYSQL_TEST_PORT_HIGH}${MYSQL_TEST_PORT_LOW}',
            'dbname' => '${MYSQL_TEST_DBNAME}_$SYSTEM_VARIANT',
            'username' => 'donald',
            'password' => '1234',
        ]);

        self::assertEquals(
            $conn_info->getDSN(),
            "pgsql:host=somehost.example.com;port=4242;dbname=projXYZ_live"
        );
    }

    public function testUndefinedVarThrows()
    {
        // Ensure tests are not dependent on the current user's settings.
        putenv('MYSQL_TEST_HOST=localhost');

        $conn_info = new ConnectionInfo\Postgres();

        $this->setExpectedException(UserError::class);
        $conn_info->loadFromDef([
            'type' => 'mysql',
            'host' => '$MYSQL_TEST_HOST',
            'port' => '${INEXISTENT_ENV_VAR}',  // not defined, should throw
            'dbname' => 'projXYZ_live',
            'username' => 'donald',
            'password' => '1234',
        ]);

        // unset used environment variables so we're not disturbing other tests
        putenv('MYSQL_TEST_HOST');
    }
}
