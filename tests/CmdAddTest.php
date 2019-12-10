<?php

namespace Datahouse\Libraries\Database\Tests;

use Datahouse\Libraries\Database\Commandline\AddCommand;

/**
 * Class CmdAddTest
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class CmdAddTest extends CommandTestBase
{
    protected function genCommand()
    {
        return new AddCommand($this->dice);
    }

    public function testSuccessfulAdd()
    {
        $output = $this->tryCommand([
            'dbid' => 'default',
            'variant' => 'default',
            'path' => 'sqlite/default/schema/fourth.sql'
        ]);

        self::assertRegExp('/Updated manifest/', $output);
        self::assertRegExp('/fourth\\.sql/', $output);

        $manifest_path = $this->toplevel . '/sqlite/default/manifest';
        $manifest_data = file_get_contents($manifest_path);
        self::assertRegExp('/schema\\/fourth\\.sql/', $manifest_data);
        // yes, this is the hash of the fourth.sql file's contents
        self::assertRegExp(
            '/35243ed08c0e789b32fb6ac8c6273b71a128df0b/',
            $manifest_data
        );

        // ensure we're not adding an absolute path
        self::assertNotRegExp('/tests.data/', $manifest_data);
    }

    public function testAddMissingFile()
    {
        $output = $this->tryCommand([
            'dbid' => 'default',
            'variant' => 'default',
            'path' => 'sqlite/default/schema/inexistent.sql'
        ], 1);

        self::assertRegExp('/not exist/', $output);
        self::assertRegExp('/inexistent\\.sql/', $output);

        $manifest_path = $this->toplevel . '/sqlite/default/manifest';
        $manifest_data = file_get_contents($manifest_path);
        self::assertNotRegExp('/schema\\/inexistent\\.sql/', $manifest_data);
    }

    public function testAddPreExistingFile()
    {
        // As requested in #4064, this should not yield an error.
        $output = $this->tryCommand([
            'dbid' => 'default',
            'variant' => 'default',
            'path' => 'sqlite/default/schema/second.sql'
        ], 0);

        self::assertNotRegExp('/already/', $output);
        self::assertRegExp('/added new step/', $output);
        self::assertRegExp('/second\\.sql/', $output);
    }

    public function testAddDirectory()
    {
        $output = $this->tryCommand([
            'dbid' => 'default',
            'variant' => 'default',
            'path' => 'sqlite/default/schema/testfolder'
        ], 1);

        self::assertRegExp('/add directory/', $output);

        $manifest_path = $this->toplevel . '/sqlite/default/manifest';
        $manifest_data = file_get_contents($manifest_path);
        self::assertNotRegExp('/testfolder/', $manifest_data);
    }
}
