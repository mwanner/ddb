<?php

namespace Datahouse\Libraries\Database\Commandline;

use PDOException;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use Datahouse\Libraries\Database\DataReaders\CsvReader;
use Datahouse\Libraries\Database\DbFactory;
use Datahouse\Libraries\Database\Exceptions\UserError;
use Datahouse\Libraries\Database\InformativeRowVisitor;
use Datahouse\Libraries\Database\Logic\Comparator;
use Datahouse\Libraries\Database\Logic\StatusStatus;
use Datahouse\Libraries\Database\ManifestComparisonResult;
use Datahouse\Libraries\Database\ProjectDirectory;
use Datahouse\Libraries\Database\StaticDataComparator;

/**
 * The status command of the commandline tool.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class StatusCommand extends Command
{
    const NAME = 'status';
    const DESC = 'Compares the status of a database with the current project.';

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
        } elseif (($committedManifest === null ||
            $committedManifest->getHash() !== $currentManifest->getHash())
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
        $status = new StatusStatus($this->reporter, $fileNameDisplayFn);
        $comparator->compare($status);

        if ($status->getErrors() > 0
            || $status->getInfos() > 0
            || $status->needsManifestUpdate()
            || $status->needsForcedManifestUpdate()
        ) {
            $output->writeln("");
        }

        // Skip the status check against the database, if it's missing or not
        // initialized. Utterly no point in such an exercise.
        $connInfo = $this->loadDatabaseConfig($dbid, $variant);
        $drv = DbFactory::connectAsSuperuser($this->lookup, $dbid, $connInfo);
        if (!$drv->isInitialized()) {
            return 1;
        }

        if ($status->needsForcedManifestUpdate()) {
            $output->writeln(
                "<error>Error: changed immutable manifest step(s).</>\n\n"
                . "Hint: if you are certain you are not breaking others "
                . "databases, this error\ncan be fixed by "
                . "<comment>'ddb update --force'</comment>\n"
            );
            $output->writeln(
                "Warning: comparing with an <error>inconsistent manifest</>.\n"
            );
        } elseif ($status->needsManifestUpdate()) {
            $output->writeln(
                "Warning: comparing with an <error>inconsistent manifest</>.\n"
                . "Hint: the above warnings can be fixed by "
                . "<comment>'ddb update'</comment>\n"
            );
        }

        if ($status->getErrors() > 0) {
            throw new UserError("Inconsistent manifest.");
        }

        $output->writeln(
            "Status of database <fg=yellow>$dbid</> " .
            "compared to schema variant <fg=yellow>$variant</>:"
        );

        $variantDir = $this->project->getVariantDir($dbtype, $variant);
        $appliedSteps = $drv->enumAppliedMigrationSteps();
        $result = $currentManifest->compareWith($appliedSteps);
        $retval = 0;
        switch ($result->status) {
            case ManifestComparisonResult::SATISFIES_TARGETS:
                $output->writeln(
                    "   <fg=green;options=bold>up to date</>"
                );
                $output->writeln('');
                break;

            case ManifestComparisonResult::UNABLE_TO_MIGRATE:
                $output->writeln(
                    "   <error>cannot migrate automatically.</>"
                );
                $output->writeln('');
                $retval = 1;
                break;

            case ManifestComparisonResult::MIGRATABLE:
                assert(count($result->paths) == 1);
                $output->writeln(
                    "   <fg=yellow;options=bold>migrations pending:</>"
                );

                foreach ($result->paths[0] as $vertexId) {
                    $step = $currentManifest->getStep($vertexId);
                    $rel_fn = ProjectDirectory::relativePath(
                        $variantDir,
                        $step->filename
                    );
                    $shortHash = $this->reporter->getShortHash(
                        $step->filehash
                    );
                    $output->writeln(
                        '      ' . $shortHash . '  ' . $rel_fn
                    );
                }
                $output->writeln('');
                $retval = 1;
                break;

            default:
                assert(false);
                $retval = 1;
                break;
        }

        $unknownTables = [];
        foreach ($currentManifest->getStaticData() as $staticData) {
            $tableName = $staticData->table;
            if (!$drv->hasTable($tableName)) {
                if ($result->status ==
                    ManifestComparisonResult::SATISFIES_TARGETS
                ) {
                    $this->reporter->reportStatus(
                        "<error>Cannot compare static data for table "
                        . $staticData->table . ": table doesn't exist</error>"
                    );
                    $retval = 1;
                } else {
                    $unknownTables[] = $tableName;
                }
            }

            try {
                $ity = new CsvReader($staticData->path);
                $cmp = new StaticDataComparator(
                    $drv,
                    $tableName,
                    $ity,
                    $staticData->numPkeyColumns
                );
                $visitor = new InformativeRowVisitor();
                $cmp->run($visitor);

                if ($visitor->counters['to_update'] > 0 ||
                    $visitor->counters['to_delete'] > 0 ||
                    $visitor->counters['to_insert'] > 0
                ) {
                    $output->writeln(
                        "<fg=yellow;options=bold>modifications pending for "
                        . "static data table $tableName:</>"
                    );
                    $displayNames = [
                        'to_update' => 'update',
                        'to_insert' => 'insert',
                        'to_delete' => 'delete'
                    ];
                    foreach ($visitor->counters as $key => $count) {
                        if ($count == 0 ||
                            !array_key_exists($key, $displayNames)
                        ) {
                            continue;
                        }
                        $what = $displayNames[$key];
                        if ($count > 1) {
                            $what .= 's';
                        }
                        $output->writeln("   $count $what");
                    }
                    $output->writeln("");
                }
            } catch (PDOException $e) {
                if ($result->status ==
                    ManifestComparisonResult::SATISFIES_TARGETS
                ) {
                    $this->reporter->reportStatus(
                        "<error>Cannot compare static data for table "
                        . $staticData->table . ": " . $e->getMessage()
                        . "</error>"
                    );
                    $retval = 1;
                } else {
                    $unknownTables[] = $tableName;
                }
            }
        }

        if (count($unknownTables) > 0) {
            $this->reporter->reportStatus(
                "<fg=yellow;options=bold>"
                . "Cannot compare these static data tables</>: "
            );
            foreach ($unknownTables as $tableName) {
                $this->reporter->reportStatus("   $tableName");
            }
        }
        return $retval;
    }
}
