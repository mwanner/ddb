<?php

namespace Datahouse\Libraries\Database\Logic;

/**
 * Represents a validated single migration step, usually a single file.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2017-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class ValidationResult
{
    const VR_STATUS_MATCHES_CURRENT = 1;
    const VR_STATUS_MATCHES_HISTORIC = 2;
    const VR_STATUS_CHANGED = 10;
    const VR_STATUS_MISSING_HISTORIC = 11;
    const VR_STATUS_MISMATCH = 20;
    const VR_STATUS_MISSING = 50;

    public $status;
    public $origFileName;
    public $usedFileName;
    public $mutable;
    public $effectiveHash;
    public $manifestHash;

    /**
     * @param int    $status        one of the VR_STATUS constants above
     * @param string $origFileName  original file name
     * @param string $usedFileName  effectively used path, relative to the
     *                              project
     * @param bool   $mutable       is it a mutable step
     * @param string $effectiveHash effective hash of the opened file
     * @param string $manifestHash  hash stated in the manifest
     */
    public function __construct(
        $status,
        $origFileName,
        $usedFileName,
        $mutable,
        $effectiveHash,
        $manifestHash
    ) {
        $this->status = $status;
        $this->origFileName = $origFileName;
        $this->usedFileName = $usedFileName;
        $this->mutable = $mutable;
        $this->effectiveHash = $effectiveHash;
        $this->manifestHash = $manifestHash;
    }
}
