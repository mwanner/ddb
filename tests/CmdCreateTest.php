<?php

namespace Datahouse\Libraries\Database\Tests;

use Datahouse\Libraries\Database\Commandline\CreateCommand;

/**
 * Class CmdAddTest
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class CmdCreateTest extends CommandTestBase
{
    protected function genCommand()
    {
        return new CreateCommand($this->dice);
    }

    public function testSimpleCreateDatabase()
    {
        $output = $this->tryCommand([
            'dbid' => 'default',
            'variant' => 'default'
        ]);

        self::assertRegExp('/Creating database/', $output);
        self::assertRegExp('/Applying 3 migration steps/', $output);
    }

    public function testOverrideInexistentDatabase()
    {
        $output = $this->tryCommand([
            'dbid' => 'default',
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
            'dbid' => 'default',
            'variant' => 'default',
            '--override' => true
        ]);

        self::assertRegExp('/Dropping database/', $output);
        self::assertRegExp('/Creating database/', $output);
        self::assertRegExp('/Applying 3 migration steps/', $output);
    }
}
