<?php

namespace Datahouse\Libraries\Database;

use Datahouse\Libraries\Database\Logic\MigrationStep;

/**
 * Interface a status and progress reporter for a user interface needs to
 * implement.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
interface IReporter
{
    /**
     * @param int $countSteps      to process
     * @param int $countStatements to process
     * @return void
     */
    public function reportStart($countSteps, $countStatements);

    /**
     * @param string $relFilename being processed
     * @return void
     */
    public function reportStep($relFilename);

    /**
     * @return void
     */
    public function advanceWithinStep();

    /**
     * @return void
     */
    public function reportSuccess();

    /**
     * @param MigrationStep $step        that failed
     * @param string        $relFilename being processed
     * @param \Throwable    $exception   failure
     * @return void
     */
    public function reportFailure(
        MigrationStep $step,
        $relFilename,
        $exception
    );

    /**
     * @param string $msg to display
     * @return void
     */
    public function reportStatus($msg);

    /**
     * @param string $fullhash to shorten
     * @return string
     */
    public function getShortHash($fullhash);
}
