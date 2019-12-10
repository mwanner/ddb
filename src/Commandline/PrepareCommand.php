<?php

namespace Datahouse\Libraries\Database\Commandline;

use Datahouse\Libraries\Database\Exceptions\UserError;
use Datahouse\Libraries\Database\Logic\Comparator;
use Datahouse\Libraries\Database\Logic\PrepareStatus;
use Datahouse\Libraries\Database\ProjectDirectory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Allows fetching all required files in preparation for deployment.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2018-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class PrepareCommand extends Command
{
    const NAME = 'prepare';
    const DESC = 'Fetches old migrations steps from git to prepare deployment';

    /**
     * @param OutputInterface $output  for user feedback
     * @param string          $dbtype  to prepare
     * @param string          $variant to prepare
     * @return PrepareStatus
     */
    protected function prepareVariant(
        OutputInterface $output,
        $dbtype,
        $variant
    ) {
        $output->writeln("<fg=blue>Preparing $dbtype/$variant...</>");

        $committedManifest = $this->project->loadCommittedManifest(
            $dbtype,
            $variant
        );
        $currentManifest = $this->project->loadCurrentManifest(
            $dbtype,
            $variant
        );

        if ($committedManifest === null && $this->project->useGit()) {
            $output->writeln(
                "Working on a <fg=yellow>new, uncommitted manifest</>"
                . " for $dbtype/$variant.\n"
            );
        } elseif ($committedManifest->getHash() !== $currentManifest->getHash()
            && $this->project->useGit()
        ) {
            // Manifest has changes compared to git.
            $output->writeln(
                "Manifest for $dbtype/$variant is "
                . "<fg=yellow>modified</> locally.\n"
            );
        }

        $variantDir = $this->project->getVariantDir($dbtype, $variant);
        $fileNameDisplayFn = function ($origFileName) use ($variantDir) {
            return ProjectDirectory::relativePath(
                $variantDir,
                $this->project->getProjectRoot() . '/' . $origFileName
            );
        };

        $comparator = new Comparator($this->project, $dbtype, $variant);
        $status = new PrepareStatus($this->reporter, $fileNameDisplayFn);
        $comparator->compare($status);
        return $status;
    }

    /**
     * @return void
     */
    protected function configure()
    {
        // no options or arguments to add
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
        if (!$this->project->useGit()) {
            throw new UserError("Missing git repository.");
        }
        foreach ($this->project->enumVariants() as $variant => $dbtypes) {
            foreach ($dbtypes as $dbtype) {
                $status = $this->prepareVariant($output, $dbtype, $variant);
                if ($status->getErrors() > 0) {
                    $output->writeln("");
                    $output->writeln("<fg=red>Failed to prepare.</>");
                    return 1;
                }
            }
        }
        $output->writeln("<fg=green>All variants successfully prepared.</>");
    }
}
