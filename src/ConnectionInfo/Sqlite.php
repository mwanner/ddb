<?php

namespace Datahouse\Libraries\Database\ConnectionInfo;

use Datahouse\Libraries\Database\Exceptions\InvalidDatabaseDefinition;

/**
 * Connection Information for a Sqlite database.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2015-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class Sqlite extends BaseConnectionInfo
{
    public $path;

    /**
     * Sqlite constructor.
     * @param string|null $path to the sqlite file
     */
    public function __construct($path = null)
    {
        $this->path = $path;
    }

    public function getUniqueServerDesc()
    {
        return $this->getPdoIdentifier() . ':' . $this->path;
    }

    public function getDSN()
    {
        return $this->getPdoIdentifier() . ':' . $this->path;
    }

    public function asPdoConstructorArgs()
    {
        return [$this->getDSN()];
    }

    public function loadFromDefEnvReplaced(array $data)
    {
        foreach ($data as $key => $val) {
            switch (mb_strtolower($key)) {
                case 'type':
                    break;
                case 'path':
                case 'file':
                    $this->path = $val;
                    break;
                default:
                    throw new InvalidDatabaseDefinition(
                        "Unknown key for " . $this->getPdoIdentifier()
                        . " connection definition: '$key'"
                    );
            }
        }

        if (is_null($this->path)) {
            throw new InvalidDatabaseDefinition(
                "Missing 'path' in definition for connection definition '$key'"
            );
        }
    }

    public function isSuperuser()
    {
        return true; // IIUC sqlite3 doesn't support privilege separation
    }

    public function forDatabaseOf($other_conn_info)
    {
        // no need to copy any authentication info from $this, so we simply
        // clone the other connection info, in this case.
        return clone($other_conn_info);
    }

    /**
     * @return string
     */
    public function getType()
    {
        return 'sqlite';
    }

    /**
     * @return string
     */
    public function getPdoIdentifier()
    {
        return 'sqlite';
    }
}
