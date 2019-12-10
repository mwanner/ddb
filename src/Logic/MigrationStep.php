<?php

namespace Datahouse\Libraries\Database\Logic;

use Datahouse\Libraries\Database\Manifest;

/**
 * Represents a single migration step, usually a single file.
 *
 * Basically just a dumb structure.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class MigrationStep
{
    /**
     * @var Manifest $manifest link to the origin manifest
     */
    public $manifest;

    /**
     * @var string $filename absolute path to the migration step file
     */
    public $filename;

    /**
     * @var string $filehash git's sha1 of the file
     */
    public $filehash;

    /**
     * @var int $numStatements number of statements for this migration step
     */
    public $numStatements;

    /**
     * @var bool $mutable whether or not this step may be modified
     */
    public $mutable;

    /**
     * @var string $label an optional human readable label
     */
    public $label;

    /**
     * @var null|string[] $deps explicit parents
     */
    public $parents;

    /**
     * MigrationStep constructor.
     * @param Manifest $manifest points back to the manifest
     * @param string   $fn       filename
     * @param string   $fh       hash of the step
     * @param bool     $mutable  whether or not this is a mutable step
     * @param string   $label    optional label for this step
     * @param array    $parents  optional explicit parents of this step
     */
    public function __construct(
        Manifest $manifest,
        $fn,
        $fh,
        $mutable,
        $label = '',
        $parents = null
    ) {
        assert($fn[0] == '/');
        $this->manifest = $manifest;
        $this->filename = $fn;
        $this->filehash = $fh;
        $this->numStatements = null;
        $this->mutable = $mutable;
        assert(is_string($label));
        $this->label = $label;
        assert(is_null($parents) || is_array($parents));
        $this->parents = $parents;
    }
}
