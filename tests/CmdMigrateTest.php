<?php

namespace Datahouse\Libraries\Database\Tests;

use Datahouse\Libraries\Database\Commandline\AddCommand;
use Datahouse\Libraries\Database\Commandline\CreateCommand;
use Datahouse\Libraries\Database\Commandline\MigrateCommand;

/**
 * Class CmdMigrateTest
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class CmdMigrateTest extends CommandTestBase
{
    protected function genCommand()
    {
        return new MigrateCommand();
    }

    public function testSimpleMigration()
    {
        $this->runOtherCommand(CreateCommand::class, []);
        $this->runOtherCommand(AddCommand::class, [
            'path' => 'sqlite/default/schema/fourth.sql'
        ]);

        $output = $this->tryCommand([], 0);
        self::assertRegExp('/Applying 1 migration steps/', $output);
    }

    public function testFailingMigration()
    {
        $this->runOtherCommand(CreateCommand::class, []);
        $this->runOtherCommand(AddCommand::class, [
            'path' => 'sqlite/default/schema/invalid.sql'
        ]);

        $output = $this->tryCommand([], 1);
        self::assertRegExp('/Applying 1 migration steps/', $output);
        self::assertRegExp('/Failed applying a migration step/', $output);
    }
}
