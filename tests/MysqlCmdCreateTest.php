<?php

namespace Datahouse\Libraries\Database\Tests;

use Datahouse\Libraries\Database\Commandline\CreateCommand;
use Datahouse\Libraries\Database\Driver;

/**
 * Class MysqlCmdCreateTest
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2020 Markus Wanner <markus@bluegap.ch>
 *                2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class MysqlCmdCreateTest extends CommandTestBase
{
    /**
     * @return string default source directory for most tests relying on
     *                 this base class.
     */
    protected function getTestDataDirectory()
    {
        return __DIR__ . '/data/p6_mysql';
    }

    protected function genCommand()
    {
        return new CreateCommand($this->dice);
    }

    public function tearDown()
    {
        $lookup = $this->dice->create(
            'Datahouse\\Libraries\\Database\\ConnInfoLookup'
        );

        $testdb = $lookup->getConnInfoById('testdb');
        $superuser = $lookup->getSuperuserCIFor('testdb');

        $driver = new Driver\Mysql($superuser);
        $driver->dropDatabase($testdb);

        parent::tearDown();
    }

    public function testSimpleCreateDatabase()
    {
        $output = $this->tryCommand([
            'dbid' => 'testdb',
            'variant' => 'default'
        ]);

        self::assertRegExp('/Creating database/', $output);
        self::assertRegExp('/Applying 3 migration steps/', $output);
    }

    public function testOverrideInexistentDatabase()
    {
        $output = $this->tryCommand([
            'dbid' => 'testdb',
            'variant' => 'default',
            '--override' => true
        ]);

        self::assertRegExp('/no need to override/', $output);
        self::assertRegExp('/Creating database/', $output);
        self::assertRegExp('/Applying 3 migration steps/', $output);
    }

    public function testCreateAndOverride()
    {
        $this->testSimpleCreateDatabase();
        $output = $this->tryCommand([
            'dbid' => 'testdb',
            'variant' => 'default',
            '--override' => true
        ]);

        self::assertRegExp('/Dropping database/', $output);
        self::assertRegExp('/Creating database/', $output);
        self::assertRegExp('/Applying 3 migration steps/', $output);
    }
}
