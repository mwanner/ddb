<?php

namespace Datahouse\Libraries\Database\Commandline;

use Datahouse\Libraries\Database\ProjectDirectory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use Datahouse\Libraries\Database\ConnectionInfo\BaseConnectionInfo;
use Datahouse\Libraries\Database\Exceptions\UserError;
use Datahouse\Libraries\Database\Logic\Comparator;
use Datahouse\Libraries\Database\Logic\UpdateStatus;

/**
 * The update command of the commandline tool.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class UpdateCommand extends Command
{
    const NAME = 'update';
    const DESC = 'Updates the manifest.';

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
            'force',
            null,
            InputOption::VALUE_NONE,
            'Force overriding hashes even for committed steps'
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
        list(, $variant, $dbtype) = $this->interpretArguments($input);

        $variantDir = $this->project->getVariantDir($dbtype, $variant);
        $fileNameDisplayFn = function ($origFileName) use ($variantDir) {
            return ProjectDirectory::relativePath(
                $variantDir,
                $this->project->getProjectRoot() . '/' . $origFileName
            );
        };

        $status = new UpdateStatus(
            $this->reporter,
            $fileNameDisplayFn,
            $input->getOption('force')
        );
        $comparator = new Comparator($this->project, $dbtype, $variant);
        $comparator->compare($status);

        if ($status->getErrors() > 0) {
            $output->writeln("");
            throw new UserError("Inconsistent manifest.");
        }

        if (count($status->getUpdates()) == 0) {
            $output->writeln(
                "Manifest is <fg=green;options=bold>up to date</> - "
                . "no action performed."
            );
            return 0;
        } else {
            return $this->project->updateManifest(
                $output,
                $dbtype,
                $variant,
                $status->getUpdates()
            );
        }
    }
}
