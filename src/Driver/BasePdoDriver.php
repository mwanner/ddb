<?php

namespace Datahouse\Libraries\Database\Driver;

use Datahouse\Libraries\Database\ConnectionInfo\BaseConnectionInfo;
use Datahouse\Libraries\Database\Exceptions\DatabaseNotInitialized;
use Datahouse\Libraries\Database\Exceptions\MigrationError;
use Datahouse\Libraries\Database\Exceptions\UserError;
use Datahouse\Libraries\Database\FileChunkIterator;
use Datahouse\Libraries\Database\IReporter;
use Datahouse\Libraries\Database\Logic\AppliedStep;
use Datahouse\Libraries\Database\Logic\MigrationStep;
use Datahouse\Libraries\Database\SqlStatementExploder;

/**
 * Base schema migration driver class for PDO databases.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2015-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
abstract class BasePdoDriver
{
    /** @var \PDO $pdo the connection */
    protected $pdo;

    /**
     * @var string $serverDesc unique servec description of the current
     * connection
     */
    protected $serverDesc;

    private $initializationState;

    /**
     * BasePdoDriver constructor.
     *
     * @param BaseConnectionInfo $conn_info specifying the database to
     * connect to.
     */
    public function __construct(BaseConnectionInfo $conn_info)
    {
        $this->pdo = $this->tryConnect($conn_info);
        $this->serverDesc = $conn_info->getUniqueServerDesc();
        $this->initializationState = $this->checkInitialization();
    }

    /**
     * Try to connect to the database specified by the given $connInfo.
     *
     * @param BaseConnectionInfo $connInfo specifying the database to connect to.
     * @return \PDO a database connection
     * @throws \PDOException
     */
    public static function tryConnect(BaseConnectionInfo $connInfo)
    {
        $args = $connInfo->asPdoConstructorArgs();
        $class = new \ReflectionClass('PDO');
        /* @var \PDO $pdo */
        $pdo = $class->newInstanceArgs($args);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    /**
     * Get the database connection object to use for custom queries.
     *
     * @return \PDO for custom use
     */
    public function getPdo()
    {
        return $this->pdo;
    }

    /**
     * Check if the database specified by the given connection info exists
     * for the database system connected to.
     *
     * @param BaseConnectionInfo $connInfo the database to check
     * @return bool
     * @throws \PDOException
     */
    abstract public function hasDatabase(BaseConnectionInfo $connInfo);

    /**
     * Creates a database.
     *
     * @param BaseConnectionInfo $connInfo the database to create
     * @return void
     * @throws \PDOException
     */
    abstract public function createDatabase(BaseConnectionInfo $connInfo);


    /**
     * Check if the given role exists on the database system connected to.
     *
     * @param string $roleName to check for
     * @return bool
     * @throws \PDOException
     */
    abstract public function hasRole($roleName);

    /**
     * Creates a role.
     *
     * @param string $roleName
     * @return void
     * @throws UserError|\PDOException
     */
    abstract public function createRole($roleName);

    /**
     * Check if the given table exists in the database connected to. Note
     * that the MySQL variant is restricted to search in the current schema.
     *
     * @param string $tableName to check for
     * @return bool
     * @throws \PDOException
     */
    abstract public function hasTable($tableName);

    /**
     * Checks if the database currently connected to has been initialized to
     * work with this library.
     *
     * Please use @see BasePdoDriver::isInitialized to query initialization
     * state from an application.
     *
     * @return bool
     */
    abstract protected function checkInitialization();

    /**
     * Actual driver implementations need to override this method to perform
     * the actual initialization of the database.
     *
     * @return void
     * @throws \PDOException
     */
    abstract protected function initialize();

    /**
     * Initialize the connected database to work with this library. Usually
     * creates some sort of '__applied_migrations' table to keep track of
     * the migration steps applied.
     *
     * @return void
     * @throws \PDOException|\RuntimeException
     */
    public function initializeAndCheck()
    {
        $this->initialize();
        $this->initializationState = $this->checkInitialization();
        if (!$this->initializationState) {
            throw new \RuntimeException("database initialization failed");
        }
    }

    /**
     * Checks if the database currently connected to has been initialized to
     * work with this library. Note that the actual check in preformed at
     * construction time.
     *
     * @return bool
     */
    public function isInitialized()
    {
        return $this->initializationState;
    }

    /**
     * Queries the database for migration steps applied.
     *
     * @return AppliedStep[] migration steps applied to the database
     * @throws DatabaseNotInitialized
     */
    abstract public function enumAppliedMigrationSteps();

    /**
     * Called prior and after every migration step to give the derived
     * database driver a chance to reset any session variables or settings.
     *
     * @return void
     */
    abstract public function resetSession();

    /**
     * Register a migration step just performed with the database.
     *
     * @param string $rel_fn   migration step's relative path
     * @param string $filehash migration step's file hash
     * @return void
     * @throws \RuntimeException|\PDOException
     */
    abstract protected function registerAppliedMigration($rel_fn, $filehash);

    /**
     * Adjust the configuration of the database itself to a manifest's config.
     *
     * @param array $config
     * @return void
     * @throws \RuntimeException|\PDOException
     */
    abstract public function loadConfig(array $config);

    /**
     * @return void
     */
    abstract public function adjustDatabaseConfig();

    /**
     * Apply a given migration step to the database connected to.
     *
     * @param string        $stepPath  path relative to the db variant
     * @param string        $fileToUse real path to the file to load
     * @param MigrationStep $step      the migration step to apply to the db.
     * @param IReporter     $reporter  to let the user know what's going on
     * @return void
     * @throws MigrationError
     */
    public function performMigration(
        $stepPath,
        $fileToUse,
        MigrationStep $step,
        IReporter $reporter
    ) {
        $this->pdo->beginTransaction();

        // Just to be extra sure we perform a reset *prior* to every step as
        // well, even if we should initially be in a clean state.
        $this->resetSession();

        try {
            $sx = new SqlStatementExploder(
                new FileChunkIterator($fileToUse)
            );
            foreach ($sx as $line_no => $stmt) {
                try {
                    $rows_affected = $this->pdo->exec($stmt);
                } catch (\PDOException $e) {
                    // Calculate the proper line number within the migration
                    // script, so as to produce a useful error message.
                    throw new MigrationError(
                        $step,
                        $line_no,
                        strpos('\n', $stmt) !== false,
                        $e->getCode(),
                        $e->getMessage()
                    );
                }
                if ($rows_affected === false) {
                    // Shouldn't happen, an exception should be thrown,
                    // instead, but this is just to be extra sure.
                    throw new MigrationError(
                        $step,
                        $line_no,
                        strpos('\n', $stmt) !== false,
                        0,
                        "Error applying statement:\n$stmt"
                    );
                }

                $reporter->advanceWithinStep();
            }

            // Reset the session and register the migration step (which might
            // need the superuser rights).
            $this->resetSession();
            $this->registerAppliedMigration($stepPath, $step->filehash);

            $this->pdo->commit();
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }
    }
}
