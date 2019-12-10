<?php

namespace Datahouse\Libraries\Database;

use Dice\Dice;

use Datahouse\Libraries\Database\ConnectionInfo\BaseConnectionInfo;
use Datahouse\Libraries\Database\Driver\BasePdoDriver;
use Datahouse\Libraries\Database\Exceptions\DatabaseNotInitialized;
use Datahouse\Libraries\Database\Exceptions\MigrationNeeded;
use Datahouse\Libraries\Database\Exceptions\UserError;
use Datahouse\Libraries\Database\GitHandler;
use Datahouse\Libraries\Database\Logic\Migrator;

/**
 * Library helper class for creating database connections and checking
 * database migration states.  For a lot of users of this library, this
 * should be the main or even only entry point.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class DbFactory
{
    protected $project;
    protected $lookup;

    /**
     * @param ProjectDirectory $project location of the project itself
     * @param ConnInfoLookup   $lookup  to lookup connection definitions
     */
    public function __construct(
        ProjectDirectory $project,
        ConnInfoLookup $lookup
    ) {
        $this->project = $project;
        $this->lookup = $lookup;
    }

    /**
     * Add required Dice rules for using this library
     *
     * @param Dice $dice DI container to use
     * @return void
     */
    public static function addDiceRules(Dice $dice)
    {
        $dice->addRule(
            'Datahouse\\Libraries\\Database\\ConnInfoLookup',
            ['shared' => true]
        );

        $dice->addRule(
            'Datahouse\\Libraries\\Database\\ProjectDirectory',
            [
                'shared' => true,
                'substitutions' => [
                    'Datahouse\\Libraries\\Database\GitInterface' => [
                        'instance' => function() { return new GitHandler; }
                    ]
                ]
            ]
        );

        $dice->addRule(
            'Datahouse\\Libraries\\Database\\DbFactory',
            ['shared' => true]
        );
    }

    /**
     * Connects to the specified database, check if it matches the current
     * manifest and return a driver for it. This omits the lookup via
     * configuration files, compared to @see getDatabaseDriver.
     *
     * Note that this does not validate the manifest itself, as that's a
     * pretty time consuming operation.
     *
     * @param BaseConnectionInfo $connInfo specifies the database to check
     * @param string             $variant  schema variant to compare with
     * @return BasePdoDriver
     * @throws MigrationNeeded
     * @throws DatabaseNotInitialized
     */
    public function getSpecificDatabaseDriver($connInfo, $variant)
    {
        $drv = static::createDriverFor($connInfo);
        $manifest = $this->project->loadCurrentManifest(
            $connInfo->getType(),
            $variant
        );

        $appliedSteps = $drv->enumAppliedMigrationSteps();
        $result = $manifest->compareWith($appliedSteps);

        if ($result->status != ManifestComparisonResult::SATISFIES_TARGETS) {
            throw new MigrationNeeded();
        }

        return $drv;
    }

    /**
     * Helper function to connect to a given database, but with the rights of
     * a superuser.
     *
     * @param ConnInfoLookup     $lookup    to lookup database connection info
     * @param string             $dbid      to lookup
     * @param BaseConnectionInfo $conn_info for which to get a super-user
     * @return BasePdoDriver
     */
    public static function connectAsSuperuser(
        ConnInfoLookup $lookup,
        $dbid,
        $conn_info
    ) {
        $super_ci = $lookup->getSuperuserCIFor($dbid);
        $drv = DbFactory::createDriverFor($super_ci);
        if (!$drv->hasDatabase($conn_info)) {
            throw new UserError(
                "The database $dbid does not exist.",
                "use 'ddb create'"
            );
        } elseif (!$drv->isInitialized()) {
            throw new UserError(
                "The database $dbid needs to be initialized, first",
                "use 'ddb init'"
            );
        }
        return $drv;
    }

    /**
     * Lookup a database by id, connect to it, check if it matches the
     * current manifest and return a driver for it.
     *
     * @param string $dbid    database to connect to
     * @param string $variant schema variant to compare with
     * @return BasePdoDriver
     * @throws MigrationNeeded in case the database isn't up to date
     * @throws DatabaseNotInitialized
     */
    public function getDatabaseDriver($dbid = 'default', $variant = 'default')
    {
        $conn_info = $this->lookup->getConnInfoById($dbid);
        if (is_null($conn_info)) {
            throw new UserError("Database '$dbid' is not defined.");
        }
        return $this->getSpecificDatabaseDriver($conn_info, $variant);
    }

    /**
     * Given a database connection info, this creates a migration driver
     * object (which in turn connects to the database and can provide you a
     * properly initialized PDO object).
     *
     * @param BaseConnectionInfo $conn_info where to connect to
     * @return BasePdoDriver a migration driver object
     */
    public static function createDriverFor($conn_info)
    {
        $type = $conn_info->getType();
        $driver_class_name = Constants::$DATABASE_TYPES[$type][1];
        return new $driver_class_name($conn_info);
    }

    /**
     * Setup a test database, assuming it does not exist, creates and
     * initializes it according to the given schema variant.
     *
     * @param string             $dbid     id of the database to create
     * @param BaseConnectionInfo $connInfo database to create
     * @param string             $variant  to create
     * @return bool
     * @throws DatabaseNotInitialized
     */
    public function setupNewDatabase(
        $dbid,
        BaseConnectionInfo $connInfo,
        $variant
    ) {
        $this->lookup->addConnection($dbid, $connInfo);

        $reporter = new NullReporter();
        $creator = new DatabaseCreator($this->lookup, $reporter);
        $drv = $creator->createDatabase($dbid, $connInfo, false, false);

        $manifest = $this->project->loadCurrentManifest(
            $connInfo->getType(),
            $variant
        );
        $validationResults = $manifest->validate($this->project);

        $migrator = new Migrator($this->project, $drv, $reporter);
        return $migrator->migrateToManifest($manifest, $validationResults);
    }
}
