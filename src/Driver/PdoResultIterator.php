<?php

namespace Datahouse\Libraries\Database\Driver;

use Iterator;
use PDO;
use PDOStatement;

/**
 * PdoResultIterator
 *
 * A helper class providing a full Iterator interface to a PDO query result.
 *
 * Note that a scrollable cursor is required for use with this class. Simply
 * use the provided PREPARE_ARGUMENTS when preparing a statement.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2017-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class PdoResultIterator implements Iterator
{
    const PREPARE_ARGUMENTS = [PDO::ATTR_CURSOR => PDO::CURSOR_SCROLL];

    /* @var PDOStatement $stmt */
    private $stmt;
    /* @var int $idx */
    private $idx;
    /* @var array|bool $row */
    private $row;

    public function __construct(PDOStatement $stmt)
    {
        $this->stmt = $stmt;
        $this->idx = 0;
        $this->row = false;
    }

    /**
     * Return the current row from the result.
     * @return array|null
     */
    public function current()
    {
        return $this->row !== false ? $this->row : null;
    }

    /**
     * Move the underlying cursor forward to the next row.
     * @return void
     */
    public function next()
    {
        $ori = $this->idx == 0 ? PDO::FETCH_ORI_FIRST : PDO::FETCH_ORI_NEXT;
        $this->row = $this->stmt->fetch(PDO::FETCH_NUM, $ori);
        $this->idx += 1;
    }

    /**
     * Return the index of the current row.
     * @return int index into the result set, starting at 0.
     */
    public function key()
    {
        return $this->idx;
    }

    /**
     * Checks if the current position is valid
     * @return bool
     */
    public function valid()
    {
        return $this->row !== false;
    }

    /**
     * Rewind the cursor to the first row of the result set.
     * @return void
     */
    public function rewind()
    {
        $this->idx = 0;
        $this->next();
    }
}
