<?php

namespace Datahouse\Libraries\Database\Commandline;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use Datahouse\Libraries\Database\ConnectionInfo\BaseConnectionInfo;
use Datahouse\Libraries\Database\DbFactory;
use Datahouse\Libraries\Database\Logic\Migrator;

/**
 * The migrate command of the commandline tool.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class MigrateCommand extends Command
{
    const NAME = 'migrate';
    const DESC = 'Migrate a database to the current schema of the project.';

    /**
     * @return void
     */
    protected function configure()
    {
        $this->addDatabaseConfig();
        $this->addVariantConfig();
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
        list($dbid, $variant, $dbtype) = $this->interpretArguments($input);

        // Load and validate the manifest
        $manifest = $this->project->loadCurrentManifest($dbtype, $variant);
        $validationResults = $manifest->validate($this->project);

        $connInfo = $this->loadDatabaseConfig($dbid, $variant);
        $drv = DbFactory::connectAsSuperuser($this->lookup, $dbid, $connInfo);

        $migrator = new Migrator($this->project, $drv, $this->reporter);
        $success = $migrator->migrateToManifest($manifest, $validationResults);
        $output->writeln('');
        return $success ? 0 : 1;
    }
}
