<?php

namespace Datahouse\Libraries\Database;

use PDOException;

use Datahouse\Libraries\Database\ConnectionInfo\BaseConnectionInfo;
use Datahouse\Libraries\Database\Driver\BasePdoDriver;
use Datahouse\Libraries\Database\Exceptions\UserError;

/**
 * Class DatabaseCreator
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class DatabaseCreator
{
    private $lookup;
    private $reporter;

    /**
     * DatabaseCreator constructor.
     * @param ConnInfoLookup $lookup   helper creating connection info
     * @param IReporter      $reporter to use
     */
    public function __construct(ConnInfoLookup $lookup, IReporter $reporter)
    {
        $this->lookup = $lookup;
        $this->reporter = $reporter;
    }

    /**
     * @param PDOException $e exception to qualify
     * @return bool
     */
    public static function isIntermittentConnectionError(PDOException $e)
    {
        $msg = $e->getMessage();
        return strpos($msg, "could not translate host name") !== false
            || strpos($msg, "could not connect to server") !== false
            || (
                // MySQL
                $e->getCode() === 2002 &&
                strpos($msg, "Connection refused") !== false
            );
    }

    /**
     * @param BaseConnectionInfo $connInfo specifying the database to use
     * @param bool               $wait     for availability of the db service
     * @return BasePdoDriver
     */
    protected function connect(BaseConnectionInfo $connInfo, $wait)
    {
        $desc = $connInfo->getUniqueServerDesc();
        $retryInterval = $wait ? 0.1 : 0;
        $retryAttempts = 35;
        while (true) {
            try {
                if ($connInfo->getType() == 'sqlite') {
                    // The SQLite driver can handle database creation even if not
                    // currently connected to a database.
                    assert($connInfo instanceof ConnectionInfo\Sqlite);
                    /* @var ConnectionInfo\Sqlite $connInfo */
                    return new Driver\Sqlite($connInfo);
                } else {
                    // For all other databases, lookup a superuser connection
                    // info and use that to connect with.
                    return DbFactory::createDriverFor(
                        $this->lookup->getSuperuserConnInfoForServer($desc)
                    );
                }
            } catch (PDOException $e) {
                if ($wait
                    && $retryAttempts > 0
                    && self::isIntermittentConnectionError($e)
                ) {
                    $this->reporter->reportStatus(sprintf(
                        "Connection failed. Retrying in %0.1f seconds.",
                        $retryInterval
                    ));
                    usleep($retryInterval * 1000000);
                    $retryInterval *= 1.2;
                    $retryAttempts -= 1;
                } else {
                    throw $e;
                }
            }
        }
    }

    /**
     * Creates the database using a
     *
     * @param string             $dbid         to create
     * @param BaseConnectionInfo $connInfo     how to connect
     * @param bool               $wait         whether or not to retry
     * @param bool               $override     override any pre-existing db
     * @param bool               $failIfExists whether or not to throw
     * @return void
     */
    protected function ensureDatabaseExists(
        $dbid,
        BaseConnectionInfo $connInfo,
        $wait,
        $override,
        $failIfExists
    ) {
        $drv = $this->connect($connInfo, $wait);
        if ($drv->hasDatabase($connInfo)) {
            if ($override) {
                $this->reporter->reportStatus("Dropping database...");
                $drv->dropDatabase($connInfo);
            } elseif ($failIfExists) {
                throw new UserError(
                    "Database $dbid already exists.",
                    "You might want to consider using --migrate or "
                    . "perhaps --override, but beware\nof losing data."
                );
            } else {
                // No need to create, it's already there and we are asked not
                // to complain about it.
                return;
            }
        } elseif ($override) {
            $this->reporter->reportStatus(
                "<comment>Database doesn't exist, no need to override.</>"
            );
        }

        $this->reporter->reportStatus("Creating database...");
        $drv->createDatabase($connInfo);
    }

    /**
     * Create a database.
     *
     * @param string             $dbid         to create
     * @param BaseConnectionInfo $connInfo     how to connect
     * @param bool               $wait         whether or not to retry
     * @param bool               $override     override any pre-existing db
     * @param bool               $failIfExists whether or not to throw
     * @return BasePdoDriver
     */
    public function createDatabase(
        $dbid,
        BaseConnectionInfo $connInfo,
        $wait = false,
        $override = false,
        $failIfExists = true
    ) {
        $this->ensureDatabaseExists(
            $dbid,
            $connInfo,
            $wait,
            $override,
            $failIfExists
        );

        // Reconnect to the (possibly newly created) database.
        $connInfo = $this->lookup->getSuperuserCIFor($dbid);

        $drv = DbFactory::createDriverFor($connInfo);
        // sqlite initializes right at creation time, so we need to re-check.
        if (!$drv->isInitialized()) {
            $drv->initializeAndCheck();
        }
        return $drv;
    }
}
