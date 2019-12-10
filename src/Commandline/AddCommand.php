<?php

namespace Datahouse\Libraries\Database\Commandline;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use Datahouse\Libraries\Database\Exceptions\UserError;
use Datahouse\Libraries\Database\FileChunkIterator;
use Datahouse\Libraries\Database\Manifest;
use Datahouse\Libraries\Database\SqlStatementExploder;

/**
 * The add command of the commandline tool.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class AddCommand extends Command
{
    const NAME = 'add';
    const DESC = 'Adds a new step to the manifest.';

    /**
     * @return void
     */
    protected function configure()
    {
        $this->addOption(
            'mutable',
            '-m',
            InputOption::VALUE_NONE,
            'override mutability auto-detection'
        );

        $this->addOption(
            'immutable',
            '-i',
            InputOption::VALUE_NONE,
            'override mutability auto-detection'
        );

        $this->addArgument(
            'path',
            InputArgument::REQUIRED,
            'manifest step to add'
        );

        $this->addDatabaseConfig();
        $this->addVariantConfig();
    }

    /**
     * Run some heuristics on an SQL file to determine whether or not it
     * should be mutable.
     *
     * @param string $path of a migration step (SQL) file to check
     * @return bool if our heuristic thinks this should be mutable
     */
    private function checkMutability($path)
    {
        $sx = new SqlStatementExploder(
            new FileChunkIterator($path)
        );
        $mutable = true;
        foreach ($sx as $stmt) {
            if (!Manifest::statementMutable($stmt)) {
                $mutable = false;
            }
        }
        return $mutable;
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
        $path = getcwd() . '/' . $input->getArgument('path');
        if (!file_exists($path)) {
            throw new UserError("File does not exist: '$path'");
        }

        if (!is_file($path)) {
            throw new UserError("Cannot add directory: '$path'");
        }

        list(, $variant, $dbtype) = $this->interpretArguments($input);

        if ($input->getOption('mutable') && $input->getOption('immutable')) {
            throw new UserError(
                'mutable and immutable are mutually exclusive options',
                'Make your mind up and decide on either mutable or'
                . ' immutable, or simple skip both options and let this'
                . ' nifty little tool play with its heuristics.'
            );
        } elseif ($input->getOption('mutable')) {
            $mutable = true;
        } elseif ($input->getOption('immutable')) {
            $mutable = false;
        } else {
            $mutable = $this->checkMutability($path);
        }

        // Read the file (again...) to determine the file's hash.
        $stepContents = file_get_contents($path);

        $projectRelPath = $this->project->getRelativePath($path);
        $updates = [$projectRelPath => [
            'optype' => 'add',
            'mutable' => $mutable,
            'newHash' => Manifest::calcGitBlobHash($stepContents)
        ]];
        $this->project->updateManifest(
            $output,
            $dbtype,
            $variant,
            $updates
        );

        return 0;
    }
}
