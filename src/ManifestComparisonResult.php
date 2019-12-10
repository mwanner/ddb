<?php

namespace Datahouse\Libraries\Database;

/**
 * Result of comparison between a manifest and a database state.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class ManifestComparisonResult
{
    const UNABLE_TO_MIGRATE = -1;
    const SATISFIES_TARGETS = 1;
    const MIGRATABLE = 2;

    /**
     * @var int $status One of the above status constants.
     */
    public $status;

    /*
     * @var array paths an array of possible migration paths
     */
    public $paths;

    /**
     * ManifestComparisonResult constructor.
     * @param int $status one of the constants above
     */
    public function __construct($status)
    {
        $this->status = $status;
        $this->paths = [];
    }

    /**
     * @param int[] $stepIndices consisting of indices to steps in the manifest
     * @return void
     */
    public function addMigrationPath(array $stepIndices)
    {
        $this->paths[] = $stepIndices;
    }
}
