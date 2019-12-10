<?php

namespace Datahouse\Libraries\Database;

/**
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2018-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class NullReporter implements IReporter
{
    public function reportStart($count_steps, $count_statements)
    {
    }

    public function reportStep($rel_fn)
    {
    }

    public function advanceWithinStep()
    {
    }

    public function reportSuccess()
    {
    }

    public function reportFailure($step, $rel_fn, $exception)
    {
        throw $exception;
    }

    public function reportStatus($msg)
    {
    }

    /**
     * @param string $fullhash to shorten
     * @return string
     */
    public function getShortHash($fullhash)
    {
        return $fullhash;
    }
}
