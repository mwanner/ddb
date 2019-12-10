<?php

namespace Datahouse\Libraries\Database\Logic;

use PDOException;
use RuntimeException;

use Datahouse\Libraries\Database\CorrectiveRowVisitor;
use Datahouse\Libraries\Database\DataReaders\CsvReader;
use Datahouse\Libraries\Database\Driver\BasePdoDriver;
use Datahouse\Libraries\Database\Exceptions\MigrationError;
use Datahouse\Libraries\Database\IReporter;
use Datahouse\Libraries\Database\Manifest;
use Datahouse\Libraries\Database\ManifestComparisonResult;
use Datahouse\Libraries\Database\ProjectDirectory;
use Datahouse\Libraries\Database\StaticDataComparator;

/**
 * A helper class performing the actual migration.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class Migrator
{
    private $project;
    private $drv;
    private $reporter;

    /**
     * @param ProjectDirectory $project  to work in
     * @param BasePdoDriver    $drv      of the database
     * @param IReporter        $reporter to use
     */
    public function __construct(
        ProjectDirectory $project,
        BasePdoDriver $drv,
        IReporter $reporter
    ) {
        $this->project = $project;
        $this->drv = $drv;
        $this->reporter = $reporter;
    }

    /**
     * @param Manifest $manifest defining static data to migrate
     * @return void
     */
    private function migrateStaticData(Manifest $manifest)
    {
        foreach ($manifest->getStaticData() as $staticData) {
            $tableName = $staticData->table;
            if (!$this->drv->hasTable($tableName)) {
                $this->reporter->reportStatus(
                    "<error>Cannot apply static data: table "
                    . $staticData->table . " does not exist</error>"
                );
                continue;   // still try other static data tables
            }

            if (!file_exists($staticData->path)) {
                $this->reporter->reportStatus(
                    '<error>Missing for static data file: '
                    . $staticData->path . '</>'
                );
                continue;   // with other static data tables
            }

            if (!is_readable($staticData->path)) {
                $this->reporter->reportStatus(
                    '<error>Unable to read static data file: '
                    . $staticData->path . '</>'
                );
                continue;   // with other static data tables
            }

            $ity = new CsvReader($staticData->path);
            $attributeNames = $ity->getColumnNames();

            $cmp = new StaticDataComparator(
                $this->drv,
                $tableName,
                $ity,
                $staticData->numPkeyColumns
            );

            $paramNames = array_map(function ($attrName) {
                return ':' . $attrName;
            }, $attributeNames);
            $insertStmt = $this->drv->getPdo()->prepare(
                'INSERT INTO ' . $tableName . ' ('
                . implode(', ', $attributeNames) . ') VALUES ('
                . implode(', ', $paramNames) . ')'
            );

            $numPkeyCols = $staticData->numPkeyColumns;
            $pkeyAttrs = array_slice($attributeNames, 0, $numPkeyCols);
            $dataAttrs = array_slice($attributeNames, $numPkeyCols);

            $pkeyClauses = array_map(function ($attrName) {
                return $attrName . ' = :' . $attrName;
            }, $pkeyAttrs);
            $setClauses = array_map(function ($attrName) {
                return $attrName . ' = :' . $attrName;
            }, $dataAttrs);
            $updateStmt = $this->drv->getPdo()->prepare(
                'UPDATE ' . $tableName . ' SET ' . implode(', ', $setClauses)
                . ' WHERE ' . implode(' AND ', $pkeyClauses)
            );

            $deleteStmt = $this->drv->getPdo()->prepare(
                'DELETE FROM ' . $tableName . ' WHERE '
                . implode(' AND ', $pkeyClauses)
            );
            $visitor = new CorrectiveRowVisitor(
                $ity->getColumnNames(),
                $insertStmt,
                $updateStmt,
                $deleteStmt,
                $numPkeyCols
            );
            try {
                $cmp->run($visitor);
            } catch (PDOException $e) {
                $this->reporter->reportStatus("");
                $this->reporter->reportStatus(
                    "<error>Cannot update static data table $tableName: "
                    . $e->getMessage() . "</error>"
                );
            }
        }
    }

    /**
     * @param Manifest $manifest          to migrate to
     * @param array    $validationResults results of Manifest::validate
     * @return bool whether or not the operation succeeded, i.e. the
     * database now matches the schema variant.
     */
    public function migrateToManifest(
        $manifest,
        $validationResults
    ) {
        $manifest->ensureManifestConsistency($validationResults);

        // Create necessary roles, if not already existent.
        foreach ($manifest->getRoles() as $role) {
            if (!$this->drv->hasRole($role)) {
                $this->drv->createRole($role);
            }
        }

        // Adjust database configuration, if any.
        $this->drv->loadConfig($manifest->getConfig());
        $this->drv->adjustDatabaseConfig();

        $appliedSteps = $this->drv->enumAppliedMigrationSteps();
        $result = $manifest->compareWith($appliedSteps);

        $retval = true;   // optimism trumps
        switch ($result->status) {
            case ManifestComparisonResult::SATISFIES_TARGETS:
                // FIXME: this message is not quite appropriate if there
                // still is static data to adjust.
                $this->reporter->reportStatus(
                    "<info>Up to date, no need to migrate.</info>"
                );
                break;

            case ManifestComparisonResult::UNABLE_TO_MIGRATE:
                $this->reporter->reportStatus(
                    "<error>Cannot migrate automatically.</>"
                );
                $retval = false;
                break;

            case ManifestComparisonResult::MIGRATABLE:
                if (count($result->paths) > 1) {
                    $this->reporter->reportStatus(
                        "<error>Multiple migration paths "
                        . "not implemented, yet.</>"
                    );
                    $retval = false;
                } else {
                    $retval = $this->applyMigrationSteps(
                        $manifest,
                        $validationResults,
                        $result->paths[0]
                    );
                }
                break;

            default:
                throw new RuntimeException(
                    "unknown manifest comparison result"
                );
        }

        $this->migrateStaticData($manifest);

        return $retval;
    }

    /**
     * Tries to perform the given migration.
     *
     * @param Manifest $manifest          to use
     * @param array    $validationResults obtained
     * @param int[]    $migrationPath     to run (as indices into
     *                                              the manifest)
     * @return bool whether or not the operation succeeded
     */
    public function applyMigrationSteps(
        Manifest $manifest,
        array $validationResults,
        array $migrationPath
    ) {
        // Count total statements for a nice progress bar, first.
        $total_statements = 0;
        foreach ($migrationPath as $vertexId) {
            $step = $manifest->getStep($vertexId);
            $total_statements += $step->numStatements;
        }

        $this->reporter->reportStart(
            count($migrationPath),
            $total_statements
        );

        try {
            foreach ($migrationPath as $vertexId) {
                $step = $manifest->getStep($vertexId);
                $validationResult = $validationResults[$vertexId];
                $status = $validationResult->status;
                $relFilename = $this->project->getRelativePath($step->filename);

                $historicBlobPath = $this->project->getHistoricBlobPath(
                    $manifest->getVariantDir(),
                    $step->filehash
                );
                if (in_array($status, [
                    ValidationResult::VR_STATUS_MATCHES_CURRENT,
                    // NOTE: a step with VR_STATUS_MISMATCH_MUTABLE must be
                    // fetched from git and *not* from the file.
                ])) {
                    $stepFilePath = $step->filename;
                } else {
                    if (is_null($historicBlobPath)) {
                        throw new RuntimeException(
                            "Missing historic migration step with hash "
                            . $this->reporter->getShortHash(
                                $step->filehash
                            )
                        );
                    } else {
                        $stepFilePath = $historicBlobPath;
                    }
                }

                $this->reporter->reportStep($relFilename);
                $this->drv->performMigration(
                    $relFilename,
                    $stepFilePath,
                    $step,
                    $this->reporter
                );
            }
        } catch (MigrationError $e) {
            // FIXME: not necessarily the correct filename, if a historic
            // variant from the blob storage has been chosen.
            $step = $e->getStep();
            $relFilename = $this->project->getRelativePath($step->filename);
            $this->reporter->reportFailure($step, $relFilename, $e);
            return false;
        }

        $this->reporter->reportSuccess();
        return true;
    }
}
