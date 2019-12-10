<?php

namespace Datahouse\Libraries\Database\Driver;

use Datahouse\Libraries\Database\ConnectionInfo;
use Datahouse\Libraries\Database\ConnectionInfo\BaseConnectionInfo;
use Datahouse\Libraries\Database\Exceptions\DatabaseNotInitialized;
use Datahouse\Libraries\Database\Exceptions\UserError;
use Datahouse\Libraries\Database\Logic\AppliedStep;

/**
 * The schema migration driver for MySQL.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class Mysql extends BasePdoDriver
{
    /**
     * Mysql schema migration driver constructor.
     *
     * @param ConnectionInfo\Mysql $conn_info used to connect
     */
    public function __construct(ConnectionInfo\Mysql $conn_info)
    {
        parent::__construct($conn_info);
    }

    /**
     * {@inheritdoc}
     *
     * @param BaseConnectionInfo $connInfo the database to check
     * @return bool
     * @throws \PDOException
     */
    public function hasDatabase(BaseConnectionInfo $connInfo)
    {
        assert($connInfo instanceof ConnectionInfo\Mysql);
        /** @var ConnectionInfo\Mysql $connInfo */
        if ($connInfo->getUniqueServerDesc() != $this->serverDesc) {
            throw new UserError("Cannot query for databases on other servers");
        }
        $dbname = $connInfo->getDbName();

        // Databases are schemas are not databases in MySQL
        $sql = 'SELECT COUNT(1) FROM information_schema.schemata
                  WHERE schema_name = :dbname;';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':dbname', $dbname);
        $success = $stmt->execute();
        if (!$success) {
            throw new \LogicException(
                "Failed checking for database $dbname."
            );
        }
        $result = $stmt->fetch();
        return $result[0] >= 1 ? true : false;
    }

    /**
     * {@inheritdoc}
     *
     * @param string $roleName to check for
     * @return bool
     * @throws \PDOException
     */
    public function hasRole($roleName)
    {
        throw new \LogicException("not implemented, yet");
    }

    /**
     * Check if the given table exists in the database connected to.
     *
     * @param string $tableName to check for
     * @return bool
     */
    public function hasTable($tableName)
    {
        $sql = 'SELECT COUNT(1) FROM information_schema.tables
                  WHERE table_name = :tableName AND table_schema = DATABASE();';
        $stmt = $this->pdo->prepare($sql);
        assert($stmt !== false);
        $stmt->bindValue(':tableName', $tableName);
        $success = $stmt->execute();
        if (!$success) {
            throw new \LogicException(
                "Failed checking for table $tableName"
            );
        }
        $result = $stmt->fetch();
        return $result[0] >= 1 ? true : false;
    }

    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    protected function checkInitialization()
    {
        return $this->hasTable('__applied_migrations');
    }

    /**
     * {@inheritdoc}
     *
     * @param ConnectionInfo\BaseConnectionInfo $connInfo for the dbname
     * @return void
     */
    public function createDatabase(ConnectionInfo\BaseConnectionInfo $connInfo)
    {
        if ($connInfo->getUniqueServerDesc() != $this->serverDesc) {
            throw new UserError("Cannot create databases on other servers");
        }
        assert($connInfo instanceof ConnectionInfo\Mysql);
        /* @var ConnectionInfo\Mysql $connInfo */
        $dbname = $connInfo->getDbName();
        $this->pdo->exec('CREATE DATABASE `' . $dbname . '`;');
    }

    /**
     * {@inheritdoc}
     *
     * @param ConnectionInfo\BaseConnectionInfo $connInfo for the dbname
     * @return void
     */
    public function dropDatabase(ConnectionInfo\BaseConnectionInfo $connInfo)
    {
        if ($connInfo->getUniqueServerDesc() != $this->serverDesc) {
            throw new UserError("Cannot create databases on other servers");
        }
        assert($connInfo instanceof ConnectionInfo\Mysql);
        /* @var ConnectionInfo\Mysql $connInfo */
        $dbname = $connInfo->getDbName();
        $this->pdo->exec('DROP DATABASE `' . $dbname . '`;');
    }

    /**
     * {@inheritdoc}
     *
     * @param string $roleName to create
     * @return void
     * @throws \PDOException
     */
    public function createRole($roleName)
    {
        $this->pdo->exec('CREATE ROLE '. $roleName . ';');
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function initialize()
    {
        $this->pdo->exec('
            CREATE TABLE __applied_migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                hash CHAR(40) NOT NULL,
                filename TEXT NOT NULL,
                KEY (hash, ts)
            );
        ');
    }

    /**
     * {@inheritdoc}
     *
     * @return AppliedStep[] migration steps applied to the database
     * @throws DatabaseNotInitialized
     */
    public function enumAppliedMigrationSteps()
    {
        if ($this->isInitialized()) {
            $res = $this->pdo->query('
                SELECT ts, hash AS filehash, filename
                FROM __applied_migrations
                ORDER BY ts ASC, id ASC;
            ');
            return array_map(function ($arr) {
                return AppliedStep::fromArray($arr);
            }, $res->fetchAll(\PDO::FETCH_ASSOC));
        } else {
            throw new DatabaseNotInitialized();
        }
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
            throw new \LogicException(
                "Failed registering applied migration step"
            );
        }
    }
}
