<?php

namespace Datahouse\Libraries\Database\Commandline;

use Symfony\Component\Console\Application;

use Datahouse\Libraries\Database\Constants;

/**
 * The command line interface to work with the database library. This is the
 * main application class and a collection of all the commands provided.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2015-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class DDBApplication extends Application
{
    /**
     * DDBApplication constructor.
     */
    public function __construct()
    {
        parent::__construct(
            'The Datahouse Database Tool',
            Constants::CURRENT_VERSION
        );
        $this->setDefaultCommand('help');
    }

    /**
     * Gets the default commands that should always be available.
     *
     * @return array An array of default Command instances
     */
    protected function getDefaultCommands()
    {
        return array_map(function ($className) {
            return new $className();
        }, [
            AddCommand::class,
            CheckCommand::class,
            CreateCommand::class,
            HelpCommand::class,
            InitCommand::class,
            PrepareCommand::class,
            ListCommand::class,
            MigrateCommand::class,
            StatusCommand::class,
            UpdateCommand::class,
        ]);
    }
}
