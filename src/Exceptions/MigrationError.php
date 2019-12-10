<?php

namespace Datahouse\Libraries\Database\Exceptions;

use Datahouse\Libraries\Database\Logic\MigrationStep;

/**
 * Contains context information for migration step failures.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class MigrationError extends \Exception
{
    private $step;
    private $stmtLineNo;
    private $lineOffset;

    /**
     * @param MigrationStep $step            triggering an error
     * @param int           $stmtLineNo      line number of the statement
     * @param bool          $isMultiLineStmt stmt spans multiple lines
     * @param int|mixed     $code            error code
     * @param string        $msg             error message
     */
    public function __construct(
        MigrationStep $step,
        $stmtLineNo,
        $isMultiLineStmt,
        $code,
        $msg
    ) {
        $this->step = $step;
        $this->stmtLineNo = $stmtLineNo;
        $this->lineOffset = null;

        // It's a shame we need to parse this out of the error message. The
        // following regex is an attempt for Postgres.
        if (preg_match("/LINE (\d+)/", $msg, $matches)) {
            $this->lineOffset = intval($matches[1]) - 1;
            $msg = str_replace(
                "LINE " . $matches[1],
                "LINE " . ($this->stmtLineNo + $this->lineOffset),
                $msg
            );
        }

        // If there's just one line, the offset of the error can only ever
        // be 0.
        if (!$isMultiLineStmt && is_null($this->lineOffset)) {
            $this->lineOffset = 0;
        }

        parent::__construct($msg, intval($code));
    }

    /**
     * @return MigrationStep
     */
    public function getStep()
    {
        return $this->step;
    }

    /**
     * @return int
     */
    public function getLineNo()
    {
        return $this->stmtLineNo;
    }

    /**
     * @return int
     */
    public function getLineOffset()
    {
        return $this->lineOffset;
    }

    /**
     * @return bool
     */
    public function couldParseLineNumber()
    {
        return isset($this->lineOffset);
    }
}
