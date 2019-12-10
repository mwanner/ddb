<?php

namespace Datahouse\Libraries\Database;

/**
 * Struct StaticData
 *
 * Holds all of the information about static data sets provided by the
 * manifest.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2017-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class StaticData
{
    /* @var Manifest $manifest defining this static data definition */
    public $manifest;
    /* @var string $table to load the data to */
    public $table;
    /* @var string $path absolute path of the data */
    public $path;
    /* @var string $format data format */
    public $format;
    /* @var int $numPkeyColumns */
    public $numPkeyColumns;

    public function __construct(
        Manifest $manifest,
        $table,
        $path,
        $format,
        $numPkeyColumns = 1
    ) {
        $this->manifest = $manifest;
        $this->table = $table;
        $this->path = $path;
        $this->format = $format;
        $this->numPkeyColumns = $numPkeyColumns;
    }
}
