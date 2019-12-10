<?php

namespace Datahouse\Libraries\Database\Driver;

use Datahouse\Libraries\Database\ConnectionInfo;
use Datahouse\Libraries\Database\ConnectionInfo\BaseConnectionInfo;
use Datahouse\Libraries\Database\Exceptions\UserError;
use Datahouse\Libraries\Database\Logic\AppliedStep;

/**
 * The schema migration driver for SQLite.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2015-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class Sqlite extends BasePdoDriver
{
    /**
     * Sqlite migration driver constructor.
     *
     * @param ConnectionInfo\Sqlite $conn_info used to connect
     */
    public function __construct(ConnectionInfo\Sqlite $conn_info)
    {
        parent::__construct($conn_info);
    }

    /**
     * Reads the first few bytes of a given file to check if it's a proper
     * sqlite3 database file.
     *
     * @param string $path of the file to check
     * @return bool
     */
    public static function isValidSqlite3Header($path)
    {
        // Very quick check to see if it's actually an sqlite3 file.
        $header = file_get_contents($path, false, null, 0, 16);
        return $header == "SQLite format 3\0";
    }

    /**
     * {@inheritdoc}
     *
     * @param BaseConnectionInfo $connInfo for the dbname
     * @return \PDO a database connection
     * @throws \PDOException
     */
    public static function tryConnect(BaseConnectionInfo $connInfo)
    {
        assert($connInfo->getType() == 'sqlite');
        assert($connInfo instanceof ConnectionInfo\Sqlite);
        /* @var ConnectionInfo\Sqlite $connInfo */
        if (file_exists($connInfo->path)) {
            if (is_writable($connInfo->path)) {
                if (static::isValidSqlite3Header($connInfo->path)) {
                    return parent::tryConnect($connInfo);
                } else {
                    throw new UserError(
                        "File '$connInfo->path' is not an sqlite3 file"
                    );
                }
            } else {
                throw new UserError(
                    "Database file '" . $connInfo->path . " is not writable'"
                );
            }
        } else {
            // Special case for SQLite: an unconnected driver with pdo = null,
            // that's still capable of handling hasDatabase and
            // createDatabase. Other databases need a connection for that
            // task, while sqlite doesn't.
            return null;
        }
    }

    /**
     * {@inheritdoc}
     *
     * @param BaseConnectionInfo $connInfo the database to check
     * @return bool
     * @throws \PDOException
     */
    public function hasDatabase(ConnectionInfo\BaseConnectionInfo $connInfo)
    {
        assert($connInfo instanceof ConnectionInfo\Sqlite);
        /* @var ConnectionInfo\Sqlite $connInfo */
        if (!file_exists($connInfo->path)) {
            return false;
        }
        return static::isValidSqlite3Header($connInfo->path);
    }

    /**
     * {@inheritdoc}
     *
     * @param string $roleName to check for
     * @return bool
     */
    public function hasRole($roleName)
    {
        // Sqlite doesn't know roles.
        return false;
    }

    /**
     * {@inheritdoc}
     *
     * @param string $tableName to check for
     * @return bool
     */
    public function hasTable($tableName)
    {
        if (isset($this->pdo)) {
            $sth = $this->pdo->prepare(
                "SELECT COUNT(1) FROM sqlite_master"
                . " WHERE type='table' AND name = '$tableName';"
            );
            $sth->execute();
            return $sth->fetch()[0] >= 1 ? true : false;
        } else {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     *
     * @param ConnectionInfo\BaseConnectionInfo $connInfo for the dbname
     * @return void
     */
    public function createDatabase(ConnectionInfo\BaseConnectionInfo $connInfo)
    {
        assert($connInfo instanceof ConnectionInfo\Sqlite);
        /* @var ConnectionInfo\Sqlite $connInfo */
        if (file_exists($connInfo->path)) {
            throw new UserError("File $connInfo->path already exists.");
        }

        // Connecting via sqlite3 creates the file. Note that we dispose the
        // pdo handle here and reconnect. Good enough for creation.
        $this->pdo = parent::tryConnect($connInfo);
        $this->initializeAndCheck();
        $this->pdo = null;
    }

    /**
     * @param string $roleName to create
     * @throws UserError
     */
    public function createRole($roleName)
    {
        throw new UserError("SQLite doesn't support roles.");
    }

    /**
     * {@inheritdoc}
     *
     * @param ConnectionInfo\BaseConnectionInfo $connInfo for the dbname
     * @return void
     */
    public function dropDatabase(ConnectionInfo\BaseConnectionInfo $connInfo)
    {
        assert($connInfo instanceof ConnectionInfo\Sqlite);
        /* @var ConnectionInfo\Sqlite $connInfo */
        if (file_exists($connInfo->path)) {
            unlink($connInfo->path);
        } else {
            throw new UserError(
                "Database file '$connInfo->path' does not exist."
            );
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    protected function checkInitialization()
    {
        return isset($this->pdo)
            ? $this->hasTable('__applied_migrations')
            : false;
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    protected function initialize()
    {
        if (!isset($this->pdo)) {
            throw new UserError("Cannot initialize, database does not exist.");
        }
        $this->pdo->beginTransaction();
        $this->pdo->exec('
            CREATE TABLE __applied_migrations (
                ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                hash TEXT NOT NULL,
                filename TEXT NOT NULL,
                PRIMARY KEY (hash, ts)
            );
        ');
        $this->pdo->commit();
    }

    /**
     * {@inheritdoc}
     *
     * @return array with keys (ts, hash) of all migration steps applied
     */
    public function enumAppliedMigrationSteps()
    {
        if (!isset($this->pdo)) {
            throw new UserError("Cannot query state, database does not exist.");
        }
        $res = $this->pdo->query('
            SELECT ts, hash AS filehash, filename
            FROM __applied_migrations
            ORDER BY ts ASC, hash ASC;
        ');
        return array_map(function ($arr) {
            return AppliedStep::fromArray($arr);
        }, $res->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @return void
     */
    public function resetSession()
    {
        // not sure what, if anything, is required here.
    }

    /**
     * @param array $config
     * @return void
     */
    public function loadConfig(array $config)
    {
        // no configuration possible for MySQL, yet.
    }

    /**
     * @return void
     */
    public function adjustDatabaseConfig()
    {
        // no configuration to save.
    }

    /**
     * {@inheritdoc}
     *
     * @param string $rel_fn   file name relative to schema variant
     * @param string $filehash hash of the migration step as applied
     * @return void
     */
    public function registerAppliedMigration($rel_fn, $filehash)
    {
        if (!isset($this->pdo)) {
            throw new UserError("Cannot migrate, database does not exist.");
        }
        /** @var \PDOStatement $stmt */
        $stmt = $this->pdo->prepare('
            INSERT INTO __applied_migrations (ts, hash, filename)
            VALUES (CURRENT_TIMESTAMP, :hash, :filename);
        ');
        assert($stmt != false);
        $success = $stmt->execute([
            ':filename' => $rel_fn,
            ':hash' => $filehash
        ]);

        if (!$success) {
            throw new \RuntimeException(
                "Failed registering applied migration step"
            );
        }
    }
}
