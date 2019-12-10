<?php

namespace Datahouse\Libraries\Database\Driver;

use Datahouse\Libraries\Database\ConnectionInfo;
use Datahouse\Libraries\Database\ConnectionInfo\BaseConnectionInfo;
use Datahouse\Libraries\Database\Exceptions\DatabaseNotInitialized;
use Datahouse\Libraries\Database\Exceptions\UserError;
use Datahouse\Libraries\Database\Logic\AppliedStep;

/**
 * The schema migration driver for Postgres.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2015-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class Postgres extends BasePdoDriver
{
    private $dbname;
    private $search_path;

    /**
     * Postgres migration driver constructor.
     *
     * @param ConnectionInfo\Postgres $conn_info used to connect
     */
    public function __construct(ConnectionInfo\Postgres $conn_info)
    {
        parent::__construct($conn_info);
        $this->dbname = $conn_info->getDbName();
        $this->search_path = ['"$user"', 'public'];
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
        assert($connInfo instanceof ConnectionInfo\Postgres);
        /** @var ConnectionInfo\Postgres $connInfo */
        if ($connInfo->getUniqueServerDesc() != $this->serverDesc) {
            throw new UserError("Cannot query for databases on other servers");
        }

        $dbname = $connInfo->getDbName();

        $sql = 'SELECT COUNT(1) FROM pg_database WHERE datname = :dbname;';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':dbname', $dbname);
        $success = $stmt->execute();
        if (!$success) {
            throw new \RuntimeException(
                "Failed checking for database $dbname."
            );
        }
        $result = $stmt->fetch(\PDO::FETCH_NUM);
        return $result[0] >= 1 ? true : false;
    }

    /**
     * {@inheritdoc}
     *
     * @param string $tableName to check for
     * @return bool
     */
    public function hasTable($tableName)
    {
        $parts = explode('.', $tableName);
        assert(count($parts) <= 2);
        if (count($parts) == 2) {
            $schemaName = $parts[0];
            $tableName = $parts[1];
        } else {
            $schemaName = null;
        }

        $sql = 'SELECT COUNT(1) FROM information_schema.tables
                  WHERE (table_schema = :schemaName OR :schemaName IS NULL)
                    AND table_name = :tableName;';
        $stmt = $this->pdo->prepare($sql);
        assert($stmt !== false);
        $stmt->bindValue(':schemaName', $schemaName);
        $stmt->bindValue(':tableName', $tableName);
        $success = $stmt->execute();
        if (!$success) {
            throw new \RuntimeException(
                "Failed checking for table $tableName in $schemaName"
            );
        }
        $result = $stmt->fetch(\PDO::FETCH_NUM);
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
        $sql = "SELECT COUNT(1) FROM pg_roles WHERE rolname = :roleName";
        $stmt = $this->pdo->prepare($sql);
        assert($stmt !== false);
        $stmt->bindValue(':roleName', $roleName);
        $success = $stmt->execute();
        if (!$success) {
            throw new \RuntimeException(
                "Failed checking for role $roleName"
            );
        }
        $result = $stmt->fetch(\PDO::FETCH_NUM);
        return $result[0] >= 1 ? true : false;
    }

    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    protected function checkInitialization()
    {
        return $this->hasTable('__dh.__applied_migrations');
    }

    /**
     * {@inheritdoc}
     *
     * @param BaseConnectionInfo $connInfo for the dbname
     * @return void
     */
    public function createDatabase(BaseConnectionInfo $connInfo)
    {
        assert($connInfo instanceof ConnectionInfo\Postgres);
        /** @var ConnectionInfo\Postgres $connInfo */
        if ($connInfo->getUniqueServerDesc() != $this->serverDesc) {
            throw new UserError("Cannot create databases on other servers");
        }
        $dbname = $connInfo->getDbName();
        $this->pdo->exec('CREATE DATABASE "' . $dbname . '";');
    }

    /**
     * {@inheritdoc}
     *
     * @param BaseConnectionInfo $connInfo for the dbname
     * @return void
     */
    public function dropDatabase(BaseConnectionInfo $connInfo)
    {
        assert($connInfo instanceof ConnectionInfo\Postgres);
        /** @var ConnectionInfo\Postgres $connInfo */
        if ($connInfo->getUniqueServerDesc() != $this->serverDesc) {
            throw new UserError("Cannot create databases on other servers");
        }
        $dbname = $connInfo->getDbName();
        $this->pdo->exec('DROP DATABASE "' . $dbname . '";');
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
        $this->pdo->exec('CREATE ROLE "'. $roleName . '" NOLOGIN;');
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    protected function initialize()
    {
        $this->pdo->beginTransaction();
        $this->pdo->exec('CREATE SCHEMA IF NOT EXISTS __dh;');
        $this->pdo->exec('
            CREATE TABLE __dh.__applied_migrations (
                ts TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now(),
                hash CHAR(40) NOT NULL,
                filename TEXT NOT NULL,
                PRIMARY KEY (hash, ts)
            );
        ');
        $this->pdo->exec('GRANT USAGE ON SCHEMA __dh TO public;');
        $this->pdo->exec(
            'GRANT SELECT ON __dh.__applied_migrations TO public;'
        );
        $this->pdo->commit();

        if (!$this->hasTable('__dh.__applied_migrations')) {
            throw new \Exception("Initialization has gone terribly wrong.");
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, mixed>
     * @throws DatabaseNotInitialized
     */
    public function enumAppliedMigrationSteps()
    {
        if ($this->isInitialized()) {
            $res = $this->pdo->query('
                SELECT ts, hash AS filehash, filename
                FROM __dh.__applied_migrations
                ORDER BY ts ASC, hash ASC;
            ');
            return array_map(function ($arr) {
                return AppliedStep::fromArray($arr);
            }, $res->fetchAll(\PDO::FETCH_ASSOC));
        } else {
            throw new DatabaseNotInitialized();
        }
    }

    /**
     * For now, this just resets the role and the search_path.
     *
     * @return void
     */
    public function resetSession()
    {
        $this->pdo->query('RESET ROLE');

        $search_path = implode(', ', $this->search_path);
        $this->pdo->exec("SET search_path = $search_path;");
    }

    /**
     * @return void
     */
    public function adjustDatabaseConfig()
    {
        $this->pdo->exec(
            'ALTER DATABASE "' . $this->dbname
            . '" SET search_path = '. implode(', ', $this->search_path) . ';'
        );

        // Apply for the current session as well, it won't change,
        // otherwise.
        $this->resetSession();
    }

    /**
     * @param array $config
     * @return void
     */
    public function loadConfig(array $config)
    {
        if (array_key_exists('search_path', $config)) {
            if (array_key_exists('prepend', $config['search_path'])) {
                $this->search_path = $config['search_path']['prepend']
                    + $this->search_path;
            }

            if (array_key_exists('append', $config['search_path'])) {
                $this->separch_path = $this->search_path +
                    $config['search_path']['append'];
            }

            if (array_key_exists('remove', $config['search_path'])) {
                $this->search_path = array_diff(
                    $this->search_path,
                    $config['search_path']['remove']
                );
            }

            $this->adjustDatabaseConfig();
        }
    }

    /**
     * @param string $schemaName to prepend
     * @return void
     */
    public function prependSearchPath($schemaName)
    {
        $this->search_path = [$schemaName] + $this->search_path;
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
            INSERT INTO __dh.__applied_migrations (ts, hash, filename)
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
