<?php

namespace Datahouse\Libraries\Database\ConnectionInfo;

/**
 * Simple structure keeping connection information for a Postgres Database.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2015-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class Postgres extends NetworkConnectionInfo
{
    const PDO_IDENTIFIER = 'pgsql';

    /**
     * Returns the Postgres default port.
     *
     * @return int
     */
    public function getDefaultPort()
    {
        return 5432;
    }

    /**
     * @return string
     */
    public function getType()
    {
        return 'postgres';
    }

    /**
     * @return string
     */
    public function getPdoIdentifier()
    {
        return self::PDO_IDENTIFIER;
    }

    /**
     * @return array all info ready to be passed to a PDO constructor
     */
    public function asPdoConstructorArgs()
    {
        assert(isset($this->client_encoding));
        $enriched_dsn = $this->getDSN()
            . " options='--client_encoding=" . $this->client_encoding . "'";
        return [$enriched_dsn, $this->username, $this->password];
    }
}
