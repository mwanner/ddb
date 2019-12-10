<?php

namespace Datahouse\Libraries\Database\Commandline;

use PDO;
use PDOException;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use Datahouse\Libraries\Database\ConnectionInfo\BaseConnectionInfo;
use Datahouse\Libraries\Database\Constants;
use Datahouse\Libraries\Database\DbFactory;
use Datahouse\Libraries\Database\Driver\BasePdoDriver;
use Datahouse\Libraries\Database\Exceptions\UserError;
use Datahouse\Libraries\Database\FileChunkIterator;
use Datahouse\Libraries\Database\Manifest;
use Datahouse\Libraries\Database\SqlStatementExploder;

/**
 * The status command of the commandline tool.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2017-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class CheckCommand extends Command
{
    const NAME = 'check';
    const DESC = 'Runs unit tests for the database functions.';

    /**
     * @return void
     */
    protected function configure()
    {
        $this->addDatabaseConfig();
        $this->addVariantConfig();
    }

    /**
     * @param string $stmt to test
     * @return boolean
     */
    protected function isValidTestStatement($stmt)
    {
        $words = preg_split('/\s+/', strtoupper($stmt));

        $acceptedCommand = false;
        foreach (Constants::$ACCEPTABLE_TEST_OBJECTS as $acceptedStart) {
            $firstWords = array_slice($words, 0, count($acceptedStart));
            if ($firstWords == $acceptedStart) {
                $acceptedCommand = true;
            }
        }
        return $acceptedCommand;
    }

    /**
     * @param Manifest      $manifest defining the tests to setup
     * @param BasePdoDriver $drv      where to setup the test schema
     * @return void
     * @throws PDOException
     * @throws UserError
     */
    protected function setupTestSchema(
        Manifest $manifest,
        BasePdoDriver $drv
    ) {
        // Adjust database configuration, if any. Then prepend the schema
        // name 'tests'.
        $config = $manifest->getConfig();
        if (!array_key_exists('prepend', $config['search_path'])) {
            $config['search_path']['prepend'] = ['tests'];
        } else {
            $config['search_path']['prepend'] = array_merge(
                ['tests'],
                $config['search_path']['prepend']
            );
        }

        $drv->loadConfig($config);

        $pdo = $drv->getPdo();
        $pdo->beginTransaction();
        $success = false;
        try {
            $pdo->exec('CREATE SCHEMA tests;');
            try {
                $pdo->exec('CREATE EXTENSION pgtap WITH SCHEMA tests;');
            } catch (PDOException $e) {
                throw new UserError(
                    "Cannot create extension pgtap: " . $e->getMessage(),
                    "You might need to install postgresql-X.Y-pgtap (on " .
                    "the server running these tests)."
                );
            }
            foreach ($manifest->getTests() as $path) {
                $relFilename = $this->project->getRelativePath($path);
                if (!file_exists($path)) {
                    throw new UserError(
                        "Configured test $relFilename cound not be found."
                    );
                }

                $statements = new SqlStatementExploder(
                    new FileChunkIterator($path)
                );

                foreach ($statements as $line_no => $stmt) {
                    $stmt = trim($stmt);
                    if (!$this->isValidTestStatement($stmt)) {
                        $pdo->rollBack();
                        throw new UserError(
                            "Invalid SQL for test ($relFilename:$line_no)",
                            "Only CREATE FUNCTION is allowed for SQL files "
                            . "defining tests to run."
                        );
                    }

                    try {
                        $rowsAffected = $pdo->exec($stmt);
                        if ($rowsAffected !== 0 && $rowsAffected == false) {
                            throw new \RuntimeException(
                                "Error applying statement:\n$stmt"
                            );
                        }
                    } catch (PDOException $e) {
                        throw new UserError(
                            "Failed to create test ($relFilename:$line_no)",
                            "Application of the test file failed with the "
                            . "following error:\n\n" . $e->getMessage()
                        );
                    }
                }
            }

            // Commit all of the test functions.
            $success = true;
        } finally {
            if ($success) {
                $pdo->commit();
            } else {
                $pdo->rollBack();
            }
        }
    }

    /**
     * @param PDO $pdo to use
     * @return string[]
     */
    protected function enumTests(PDO $pdo)
    {
        try {
            $pdo->beginTransaction();

            $res = $pdo->query(
                "SELECT routines.routine_name
    FROM information_schema.routines
    WHERE routines.specific_schema='tests'
      AND routines.routine_name LIKE 'test_%'
    ORDER BY routines.routine_name;
    ",
                PDO::FETCH_NUM
            );
            return array_map(function ($row) {
                return $row[0];
            }, $res->fetchAll());
        } finally {
            $pdo->rollBack();
        }
    }

    /**
     * @param BasePdoDriver $drv        to use
     * @param string        $testFnName to invoke
     * @return array
     */
    protected function runSingleTest(BasePdoDriver $drv, $testFnName)
    {
        try {
            $drv->resetSession();
            $pdo = $drv->getPdo();

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "SELECT * FROM runtests('tests', :testFnName)"
            );
            $stmt->execute([':testFnName' => $testFnName]);
            $lines = array_map(function ($row) {
                return $row[0];
            }, $stmt->fetchAll());
            $success = null;
            $subTestResults = [];
            foreach ($lines as $line) {
                if ($line[0] == '#') {
                    // comment, ignore
                } elseif ($line[0] == ' ') {
                    // collect sub test result
                    $subTestResults[] = ltrim($line);
                } elseif (substr($line, 0, 2) == 'ok') {
                    // test successful
                    $success = true;
                } elseif (substr($line, 0, 6) == 'not ok') {
                    // test failed
                    $success = false;
                }
            }
            assert(!is_null($success));
            return [$success, $subTestResults, null];
        } catch (PDOException $e) {
            // exception during running test
            return [false, [], $e];
        } finally {
            $pdo->rollBack();
        }
    }

    /**
     * @param BasePdoDriver $drv of the database
     * @return array
     */
    protected function runTests(BasePdoDriver $drv)
    {
        $pdo = $drv->getPdo();
        $tests = $this->enumTests($pdo);
        if (count($tests) == 0) {
            throw new UserError(
                "No test functions found.",
                "Please create at least one function starting with 'test_' "
                . "in one of the files listed under 'tests' in the manifest."
            );
        }

        $total = count($tests);
        $this->reporter->reportStart($total, $total);

        $testResults = [];
        foreach ($tests as $testFnName) {
            $this->reporter->reportStep($testFnName);
            $testResults[$testFnName]
                = $this->runSingleTest($drv, $testFnName);
        }

        // FIXME: not quite appropriate for failures, but hey...
        $this->reporter->reportSuccess();

        return $testResults;
    }

    /**
     * @param BasePdoDriver $drv of the database
     * @return void
     */
    protected function tearDownTestSchema(BasePdoDriver $drv)
    {
        $pdo = $drv->getPdo();
        $pdo->beginTransaction();
        $pdo->exec('DROP SCHEMA IF EXISTS tests CASCADE;');
        $pdo->commit();
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

        if ($dbtype !== 'postgres') {
            throw new UserError("No unit testing support for $dbtype.");
        }

        $currentManifest = $this->project->loadCurrentManifest(
            $dbtype,
            $variant
        );
        $validationResults = $currentManifest->validate($this->project);
        $currentManifest->ensureManifestConsistency($validationResults);

        $tests = $currentManifest->getTests();
        if (count($tests) == 0) {
            throw new UserError(
                "No tests configured",
                "Add a 'tests' directive to the manifest."
            );
        }

        $connInfo = $this->loadDatabaseConfig($dbid, $variant);
        $drv = DbFactory::connectAsSuperuser($this->lookup, $dbid, $connInfo);

        try {
            $this->reporter->reportStatus("set up test suite");
            $this->setupTestSchema($currentManifest, $drv);
            $testResults = $this->runTests($drv);
        } finally {
            $this->reporter->reportStatus("tear down test suite");
            $this->tearDownTestSchema($drv);
            echo "\n\n";
        }

        $total = count($testResults);
        $exitCode = 0;
        $successes = 0;
        $exceptionCount = 0;
        $failureCount = 0;
        foreach ($testResults as $testFnName => $testResult) {
            list ($success, $subTestResults, $exceptionCaught)
                = $testResult;
            if ($success) {
                $successes += 1;
            } elseif (isset($exceptionCaught)) {
                $exitCode = 1;
                $exceptionCount += 1;
            } else {
                $exitCode = 1;
                $failureCount += 1;
            }
        }

        if ($total == $successes) {
            if ($total == 1) {
                echo "The one and only test succeeded.\n";
            } else {
                echo "All of $successes tests succeeded.\n";
            }
        } else {
            foreach ($testResults as $testFnName => $testResult) {
                list ($success, $subTestResults, $exceptionCaught)
                    = $testResult;
                if (!$success && isset($exceptionCaught)) {
                    echo str_repeat('=', 76) . "\n";
                    echo "$testFnName threw an exception:\n\n";
                    echo $exceptionCaught->getMessage();
                    echo "\n";
                } elseif (!$success) {
                    echo str_repeat('=', 76) . "\n";
                    echo "$testFnName failed:\n";
                    foreach ($subTestResults as $line) {
                        echo "    $line\n";
                    }
                    echo "\n";
                }
            }

            echo "\n";
            echo str_repeat('=', 76) . "\n";
            $what = $total == 1 ? "test" : "tests";
            echo "Run $total $what in total:\n";
            if ($successes > 0) {
                echo "    $successes succeeded\n";
            }
            if ($failureCount > 0) {
                echo "    $failureCount failed\n";
            }
            if ($exceptionCount > 0) {
                echo "    $exceptionCount threw an exception\n";
            }
        }
        echo "\n";

        return $exitCode;
    }
}
