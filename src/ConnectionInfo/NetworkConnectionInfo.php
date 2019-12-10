<?php

namespace Datahouse\Libraries\Database\ConnectionInfo;

use Datahouse\Libraries\Database\Exceptions\UserError;

/**
 * A base class for connection information for database systems that expose
 * their service via the network.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2015-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
abstract class NetworkConnectionInfo extends BaseConnectionInfo
{
    protected $host;
    protected $port;
    protected $dbname;
    protected $username;
    protected $password;
    protected $client_encoding;

    /** @var bool $superuser */
    protected $superuser;

    /**
     * NetworkConnectionInfo constructor.
     * @param string $host     host running the networked db service
     * @param int    $port     port to connect to
     * @param string $dbname   name of the database to use
     * @param string $username to authenticate with
     * @param string $password to authenticate with
     */
    public function __construct(
        $host = null,
        $port = null,
        $dbname = null,
        $username = null,
        $password = null
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->dbname = $dbname;
        $this->username = $username;
        $this->password = $password;
        $this->superuser = false;
        // Use a sane default. FIXME: cannot currently be overridden.
        $this->client_encoding = 'utf8';
    }

    /**
     * @param array $data environment variables
     * @return void
     * @throws UserError
     */
    public function loadFromDefEnvReplaced(array $data)
    {
        foreach ($data as $key => $val) {
            if (is_string($val)) {
                $val = static::replaceEnvVars($val);
            }
            switch (mb_strtolower($key)) {
                case 'type':
                    break;
                case 'host':
                    $this->host = $val;
                    break;
                case 'port':
                    $this->port = $val;
                    break;
                case 'dbname':
                case 'database':
                    $this->dbname = $val;
                    break;
                case 'user':
                case 'username':
                    $this->username = $val;
                    break;
                case 'passwd':
                case 'password':
                    $this->password = $val;
                    break;
                case 'superuser':
                case 'root':
                    $this->superuser = boolval($val);
                    break;
                default:
                    throw new UserError(
                        "Unknown key for " . $this->getPdoIdentifier()
                        . " connection definition: '$key'"
                    );
            }
        }

        if (is_null($this->host)) {
            $this->host = "localhost";
        }

        if (is_null($this->username)) {
            $this->username = getenv("USER");
        }
    }

    /**
     * Create a connection info object for the same server and user, but for a
     * different database.
     *
     * @param NetworkConnectionInfo $other_conn_info dbname to use
     * @return NetworkConnectionInfo a new connection info object
     */
    public function forDatabaseOf($other_conn_info)
    {
        $c = clone($this);
        $c->dbname = $other_conn_info->dbname;
        return $c;
    }

    /**
     * @return int
     */
    abstract public function getDefaultPort();

    /**
     * Returns true, this ConnInfo would use the default port for the given
     * database system.
     *
     * @return bool
     */
    public function isDefaultPort()
    {
        return is_null($this->port) || $this->port == $this->getDefaultPort();
    }

    /**
     * @return string
     */
    public function getFqdn()
    {
        if (!is_null($this->host) && $this->host != "localhost") {
            // We don't really care what IP the host name resolves to, but
            // the DNS usually gets us a FQDN.
            try {
                $dnsResult = @dns_get_record($this->host);
            } catch (\ErrorException $e) {
                $dnsResult = [];
            }
            if (is_array($dnsResult)) {
                foreach ($dnsResult as $rec) {
                    return $rec['host'];
                }
            }
            // Fall-back to using the host name provided, if we cannot
            // currently resolve it.
            return $this->host;
        } else {
            return "localhost";   // my home always qualifies - sure, fully!
        }
    }

    /**
     * @return string a unique hashable description of the connection
     */
    public function getUniqueServerDesc()
    {
        $desc = $this->getPdoIdentifier() . ':' . $this->getFqdn();
        if (!$this->isDefaultPort()) {
            $desc .= ":" . $this->port;
        }
        return $desc;
    }

    /**
     * @return string the username used to connect
     */
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * @return string the password for the user to connect with
     */
    public function getPassword()
    {
        return $this->password;
    }

    /**
     * @return string database name used to connect
     */
    public function getDbName()
    {
        return $this->dbname;
    }

    /**
     * @return string the connection info as a DNS for PDO
     */
    public function getDSN()
    {
        return $this->getPdoIdentifier() . ':'
            . implode(';', $this->getDSNParts());
    }

    /**
     * @return array parts to assemble a DSN
     */
    public function getDSNParts()
    {
        $parts = [];
        if (!is_null($this->host)) {
            $parts[] = "host=$this->host";
        } else {
            $parts[] = "host=localhost";
        }
        if (!is_null($this->port) && !$this->isDefaultPort()) {
            $parts[] = "port=$this->port";
        }
        if (!is_null($this->dbname)) {
            $parts[] = "dbname=$this->dbname";
        }
        return $parts;
    }

    /**
     * @return bool whether or not this connection is supposed to have
     * superuser privileges.
     */
    public function isSuperuser()
    {
        return $this->superuser;
    }
}
