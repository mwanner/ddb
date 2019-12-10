<?php

namespace Datahouse\Libraries\Database;

/**
 * Constants used for the Database Library and Tool.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2017-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class Constants
{
    /**
     * Current version of the database library (and tool). Should better match
     * with git tag...
     */
    const CURRENT_VERSION = '1.6.0';

    /**
     * Registry of supported database types.
     */
    public static $DATABASE_TYPES = [
        'postgres' => [
            '\\Datahouse\\Libraries\\Database\\ConnectionInfo\\Postgres',
            '\\Datahouse\\Libraries\\Database\\Driver\\Postgres'
        ],
        'mysql' => [
            '\\Datahouse\\Libraries\\Database\\ConnectionInfo\\Mysql',
            '\\Datahouse\\Libraries\\Database\\Driver\Mysql'
        ],
        'sqlite' => [
            '\\Datahouse\\Libraries\\Database\\ConnectionInfo\\Sqlite',
            '\\Datahouse\\Libraries\\Database\\Driver\Sqlite'
        ]
    ];

    /**
     * Path to the manifest file, relative to the database directory in the
     * project, i.e. dev/mysql/$id/manifest
     */
    const MANIFEST_FILENAME = 'manifest';

    /**
     * Name of the directory containing files common to all variants. Shouldn't
     * ever contain a manifest.
     */
    const COMMON_DIRECTORY = 'common';

    /** Absolute path of the git VCS tool. */
    const GIT_CMD = '/usr/bin/git';

    const DEFAULT_VARIANT = 'default';

    const DEFAULT_DATABASE_ID = 'default';

    /** Location of the user's databases, relative to $HOME */
    const USER_CONFIG_FILE = ".datahouse/databases.json";

    /**
     * Location of the project's database configuration file, relative to the
     * project root.
     */
    const PROJECT_CONFIG_FILE = "db.conf.json";

    /**
     * Location of historic blobs retrieved from git, relative to the project
     * root.
     */
    const BLOB_DIR = ".ddb/blob";

    /**
     * Objects for which 'CREATE OR REPLACE' is considered mutable.
     */
    public static $MUTABLE_OBJECTS = [
        'FUNCTION' => true,
        'GRANT' => true,
        'PROCEDURE' => true,
        'REVOKE' => true,
        'VIEW' => true
    ];

    public static $ACCEPTABLE_TEST_OBJECTS = [
        ['CREATE', 'FUNCTION'],
        ['CREATE', 'OR', 'REPLACE', 'FUNCTION']
    ];
}
