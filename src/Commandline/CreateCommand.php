<?php

namespace Datahouse\Libraries\Database\Commandline;

use Datahouse\Libraries\Database\Exceptions\UserError;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use Datahouse\Libraries\Database\DatabaseCreator;
use Datahouse\Libraries\Database\Logic\Migrator;

/**
 * The create command of the commandline tool.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class CreateCommand extends Command
{
    const NAME = 'create';
    const DESC = 'Create a new database according to a schema variant.';

    /**
     * Add configuration options for this command.
     *
     * @return void
     */
    protected function configure()
    {
        $this->addDatabaseConfig();
        $this->addVariantConfig();
        $this->addOption(
            'override',
            null,
            InputOption::VALUE_NONE,
            'Allow overriding an existing database'
        );
        $this->addOption(
            'wait',
            'w',
            InputOption::VALUE_NONE,
            'Wait for the server to be ready'
        );
        $this->addOption(
            'migrate',
            null,
            InputOption::VALUE_NONE,
            "Do not fail if the database exists, but perform a\nmigration, instead"
        );
    }

    /**
     * {@inheritdoc}
     *
     * @param InputInterface  $input  console input
     * @param OutputInterface $output console output
     * @return int exit code
     */
    protected function coveredExecute(
        InputInterface $input,
        OutputInterface $output
    ) {
        list($dbid, $variant,) = $this->interpretArguments($input);
        $connInfo = $this->loadDatabaseConfig($dbid, $variant);

        $override = $input->getOption('override');
        $migrate = $input->getOption('migrate');
        if ($migrate && $override) {
            throw new UserError(
                "An existing database can either be overridden or migrated, "
                . "but not both."
            );
        }

        // Load the manifest
        $manifest = $this->project->loadCurrentManifest(
            $connInfo->getType(),
            $variant
        );
        $validationResults = $manifest->validate($this->project);
        $manifest->ensureManifestConsistency($validationResults);

        // Create the database
        $creator = new DatabaseCreator($this->lookup, $this->reporter);
        $drv = $creator->createDatabase(
            $dbid,
            $connInfo,
            $input->getOption('wait'),
            $override,
            !$migrate
        );

        // Migrate
        $migrator = new Migrator($this->project, $drv, $this->reporter);
        $success = $migrator->migrateToManifest($manifest, $validationResults);
        $output->writeln('');
        return $success ? 0 : 1;
    }
}
