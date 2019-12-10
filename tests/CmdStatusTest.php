<?php

namespace Datahouse\Libraries\Database\Tests;

use Datahouse\Libraries\Database\Commandline\AddCommand;
use Datahouse\Libraries\Database\Commandline\CreateCommand;
use Datahouse\Libraries\Database\Commandline\StatusCommand;

/**
 * Class Datahouse\Libraries\Database\Tests
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class CmdStatusTest extends CommandTestBase
{
    protected function genCommand()
    {
        return new StatusCommand($this->dice);
    }

    public function testMissingDatabase()
    {
        $output = $this->tryCommand([
            'dbid' => 'default',
            'variant' => 'default'
        ], 1);

        self::assertRegExp('/database default does not exist/', $output);
    }

    public function testSimpleStatus()
    {
        $this->runOtherCommand(CreateCommand::class, []);

        $output = $this->tryCommand([
            'dbid' => 'default',
            'variant' => 'default'
        ]);

        self::assertRegExp('/new, uncommitted manifest/', $output);
        self::assertRegExp('/up to date/', $output);
    }

    public function testStatusAfterAdd()
    {
        $this->runOtherCommand(CreateCommand::class, []);
        $this->runOtherCommand(AddCommand::class, [
            'path' => 'sqlite/default/schema/fourth.sql'
        ]);

        $output = $this->tryCommand([], 1);

        self::assertRegExp('/new, uncommitted manifest/', $output);
        self::assertRegExp('/migrations pending/', $output);
    }
}
