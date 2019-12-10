<?php

namespace Datahouse\Libraries\Database\Logic;

/**
 * Simple bean representing an applied migration step.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2015-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class AppliedStep
{
    public $ts;
    public $filehash;
    public $filename;

    /**
     * @param string $ts       timestamp of the migration performed
     * @param string $filehash hash of the migration step file
     * @param string $filename name of the migration step file
     */
    public function __construct($ts, $filehash, $filename)
    {
        $this->ts = $ts;
        $this->filehash = $filehash;
        $this->filename = $filename;
    }

    /**
     * @param array<string, string> $arr to create object from
     * @return AppliedStep
     */
    public static function fromArray(array $arr)
    {
        return new AppliedStep(
            $arr['ts'],
            $arr['filehash'],
            $arr['filename']
        );
    }
}