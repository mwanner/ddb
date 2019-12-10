<?php

namespace Datahouse\Libraries\Database\ConnectionInfo;

use Datahouse\Libraries\Database\Exceptions\UserError;

/**
 * Simple structure keeping connection information for all kinds of
 * databases supported. Common abstract base class.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2015-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
abstract class BaseConnectionInfo
{
    /**
     * @return string specifying the kind of database, as understood by PDO
     */
    abstract public function getPdoIdentifier();

    /**
     * Populate the connection info object with data from a yaml or json
     * definition, performs variable replacement.
     *
     * @param array $data definition of the connection
     * @return null
     */
    public function loadFromDef(array $data)
    {
        foreach ($data as &$value) {
            if (is_string($value)) {
                $value = static::replaceEnvVars($value);
            }
        }
        unset($value);
        return $this->loadFromDefEnvReplaced($data);
    }

    /**
     * Replace dollar initiated variable names in database config files.
     *
     * @param string $val with variables to replace
     * @return string
     */
    public static function replaceEnvVars($val)
    {
        $pattern = '/\$([\w_]+|\{[\w_]+\})/';
        $flags = PREG_SET_ORDER | PREG_OFFSET_CAPTURE;
        if (preg_match_all($pattern, $val, $matches, $flags)) {
            foreach (array_reverse($matches) as $match) {
                list($matched_str, $offset) = $match[0];
                if ($matched_str[1] == '{') {
                    $env_var = substr(
                        $matched_str,
                        2,
                        strlen($matched_str) - 3
                    );
                } else {
                    $env_var = substr($matched_str, 1);
                }
                $replacement = getenv($env_var);
                if ($replacement === false) {
                    throw new UserError("Undefined variable: '$env_var'");
                }
                $val = substr($val, 0, $offset)
                    . $replacement
                    . substr($val, $offset + strlen($matched_str));
            }
        }
        return $val;
    }

    /**
     * Populate the connection info object with data from a yaml or json
     * definition.
     *
     * @param array $data definition of the connection
     * @return null
     */
    abstract public function loadFromDefEnvReplaced(array $data);

    /**
     * @return string a server description that can be matched to others
     */
    abstract public function getUniqueServerDesc();

    /**
     * Gets the DSN string for the database (without username and password).
     *
     * @return string a DSN string for use with PDO.
     */
    abstract public function getDSN();

    /**
     * Gets all parameters required to connect to the specified database via
     * PDO.
     *
     * @return array argument to pass to the PDO constructor.
     */
    abstract public function asPdoConstructorArgs();

    /**
     * Whether or not the specified user is supposed to have superuser
     * privileges.
     *
     * @return bool
     */
    abstract public function isSuperuser();

    /**
     * Creates a clone of this ConnectionInfo object, i.e. copies the
     * credentials for authorization, but for a different database.
     *
     * @param BaseConnectionInfo $other_conn_info specifies the database
     * @return BaseConnectionInfo
     */
    abstract public function forDatabaseOf($other_conn_info);

    /**
     * Determine the database type name of this connection info object. This
     * matches the name used in @see Constants::$DATABASE_TYPES.
     *
     * @return string database type as an index into Constants::DATABASE_TYPES
     */
    abstract public function getType();
}
