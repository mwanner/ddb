<?php

namespace Datahouse\Libraries\Database;

use Datahouse\Libraries\Database\ConnectionInfo\BaseConnectionInfo;
use Datahouse\Libraries\Database\Exceptions\UserError;
use Datahouse\Libraries\Database\Exceptions\InvalidDatabaseDefinition;
use Datahouse\Libraries\Database\Exceptions\MissingSuperuserConnInfo;

/**
 * Library helper class for looking up a database connection via a
 * database id.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class ConnInfoLookup
{
    private $json_config;

    /* @var ProjectDirectory|null $project */
    private $project;

    private $conns;
    private $per_host_conns;

    /**
     * ConnInfoLookup constructor.
     *
     * @param ProjectDirectory      $project     our project directory object
     */
    public function __construct(ProjectDirectory $project)
    {
        $this->project = $project;

        $this->conns = null;
        $this->per_host_conns = [];
    }

    public function lazyLoadConnections()
    {
        $this->conns = [];

        // Load user settings, first. May be overridden by project config.
        foreach ($this->loadUserSettings() as $name => $conn_info) {
            $this->addConnection($name, $conn_info);
        }

        $projectConns = $this->loadProjectSettings($this->project);
        foreach ($projectConns as $name => $conn_info) {
            $this->addConnection($name, $conn_info);
        }

        // Drop this reference, it's no longer needed.
        unset($this->project);
    }

    private function createConnInfoFromJson($name, array $def)
    {
        if (!array_key_exists('type', $def)) {
            throw new InvalidDatabaseDefinition(
                "Database definition for '$name' misses a type."
            );
        }

        if (!array_key_exists($def['type'], Constants::$DATABASE_TYPES)) {
            throw new UserError("Database definition for '$name' has"
                . " an unknow type: " . $def['type']);
        } else {
            $type_info = Constants::$DATABASE_TYPES[$def['type']];
            $conn_info_class_name = $type_info[0];

            /** @var BaseConnectionInfo $conn_info */
            $conn_info = new $conn_info_class_name();
            $conn_info->loadFromDef($def);
            return $conn_info;
        }
    }

    /**
     * Loads database configuration information from a JSON string.
     *
     * @param string $jsonStr The JSON data containing database connection
     * info.
     * @return array deserialized data
     * @throws UserError
     */
    private function loadFromJSON($jsonStr)
    {
        assert(is_string($jsonStr));
        $data = json_decode($jsonStr, true);
        if (is_null($data)) {
            throw new UserError(
                'Unable to decode JSON config: ' . json_last_error_msg()
            );
        }

        // Project configurations may be flat and define a 'default'
        // connection.
        $is_flat = true;
        foreach (array_values($data) as $val) {
            if (is_array($val)) {
                $is_flat = false;
            }
        }

        $result = [];
        if ($is_flat) {
            $name = Constants::DEFAULT_VARIANT;
            $result[$name] = $this->createConnInfoFromJson($name, $data);
        } else {
            foreach ($data as $name => $def) {
                $result[$name] = $this->createConnInfoFromJson($name, $def);
            }
        }
        return $result;
    }

    /**
     * Loads database configuration information from a JSON file.
     *
     * @param string $filename path to the file to load
     * @return array deserialized data
     * @throws UserError
     */
    private function loadFromFile($filename)
    {
        $json_str = file_get_contents($filename);
        if ($json_str !== false) {
            try {
                return $this->loadFromJSON($json_str);
            } catch (UserError $e) {
                throw new UserError(
                    "Error parsing file '$filename': " . $e->getMessage()
                );
            }
        } else {
            throw new UserError("Couldn't read from file '$filename'.");
        }
    }

    private function loadUserSettings()
    {
        $home = getenv("HOME");
        if ($home === false) {
            return [];
        }
        $filename = $home . '/' . Constants::USER_CONFIG_FILE;
        if (file_exists($filename)) {
            return $this->loadFromFile($filename);
        } else {
            return [];
        }
    }

    private function loadProjectSettings(ProjectDirectory $proj)
    {
        $root = $proj->getRelativeProjectRoot();
        if (!is_null($root)) {
            $cfg_file = Constants::PROJECT_CONFIG_FILE;
            if (strlen($root) > 0) {
                $cfg_file = $root . '/' . $cfg_file;
            }
            if (file_exists($cfg_file)) {
                return $this->loadFromFile($cfg_file);
            }
        }
        return [];
    }

    /**
     * Adds a connection information to the pool.
     *
     * @param string             $name      the connection name or id
     * @param BaseConnectionInfo $conn_info the connection info to add
     * @return void
     */
    public function addConnection($name, BaseConnectionInfo $conn_info)
    {
        assert(!is_null($name));
        if (is_null($this->conns)) {
            $this->lazyLoadConnections();
        }
        $this->conns[$name] = $conn_info;
        $serverDesc = $conn_info->getUniqueServerDesc();
        if (!array_key_exists($serverDesc, $this->per_host_conns)) {
            $this->per_host_conns[$serverDesc] = [];
        }
        array_push($this->per_host_conns[$serverDesc], $conn_info);
    }

    /**
     * @param string $serverDesc the unique server description of the server
     * for which superuser privileges are needed.
     *
     * @return array all connection infos found for the given server that are
     * supposed to provide superuser privileges.
     */
    private function getSuperuserConnInfoForDb($serverDesc)
    {
        return array_values(array_filter(
            array_key_exists($serverDesc, $this->per_host_conns)
                ? $this->per_host_conns[$serverDesc]
                : [],
            function (BaseConnectionInfo $conn_info) {
                return $conn_info->isSuperuser();
            }
        ));
    }

    /**
     * Returns any connection info for the given $serverDesc with superuser
     * privileges. Unlike the getSuperuserConnInfoForDb method above, this
     * might connect to any other database. Used for CREATE DATABASE and
     * similar commands.
     *
     * @param string $serverDesc as obtained by getUniqueServerDesc
     * @return BaseConnectionInfo|null
     */
    public function getSuperuserConnInfoForServer($serverDesc)
    {
        $conn_info_array = $this->getSuperuserConnInfoForDb($serverDesc);
        if (count($conn_info_array) == 0) {
            throw new MissingSuperuserConnInfo($serverDesc);
        } else {
            return $conn_info_array[0];
        }
    }

    /**
     * @return array of strings with all known connections
     */
    public function getKnownConnectionIds()
    {
        if (is_null($this->conns)) {
            $this->lazyLoadConnections();
        }
        return array_keys($this->conns);
    }

    /**
     * Lookup a database connection info as defined in a config files.
     *
     * @param string $dbid of the connection to fetch
     * @return BaseConnectionInfo|null
     */
    public function getConnInfoById($dbid)
    {
        if (is_null($this->conns)) {
            $this->lazyLoadConnections();
        }
        if (!array_key_exists($dbid, $this->conns)) {
            throw new InvalidDatabaseDefinition("Database '$dbid' is unknown.");
        } else {
            return $this->conns[$dbid];
        }
    }

    /**
     * @param string $dbid to lookup a superuser connection for
     * @return BaseConnectionInfo
     */
    public function getSuperuserCIFor($dbid)
    {
        $conn_info = $this->getConnInfoById($dbid);
        $desc = $conn_info->getUniqueServerDesc();
        $super_conn_info = $this->getSuperuserConnInfoForServer($desc);

        // The superuser connection usually is specified for a different
        // database.  But being a superuser, we have access to all databases.
        // Assemble a new ConnectionInfo object with superuser rights, but for
        // the correct database.
        return $super_conn_info->forDatabaseOf($conn_info);
    }
}
