<?php


namespace Datahouse\Libraries\Database\Commandline;

use Datahouse\Libraries\Database\Driver\BasePdoDriver;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use Datahouse\Libraries\Database\DbFactory;

/**
 * The init command of the commandline tool.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class InitCommand extends Command
{
    const NAME = 'init';
    const DESC = 'Initializes a database for use with this tool.';

    /**
     * @return void
     */
    protected function configure()
    {
        $this->addDatabaseConfig();
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
        if (!is_null($input->getArgument('dbid'))) {
            $dbid = $input->getArgument('dbid');
        } else {
            $dbid = 'default';
        }

        $super_ci = $this->lookup->getSuperuserCIFor($dbid);
        /** @var BasePdoDriver $drv */
        $drv = DbFactory::createDriverFor($super_ci);
        if ($drv->isInitialized()) {
            $output->writeln(
                "<error>The database $dbid is already initialized.</>\n"
            );
            return 1;
        } else {
            $drv->initializeAndCheck();
        }
    }
}
