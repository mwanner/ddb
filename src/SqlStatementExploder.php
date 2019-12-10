<?php

namespace Datahouse\Libraries\Database;

use Datahouse\Libraries\Database\Exceptions\StatementExploderException;

/**
 * Class SqlStatementExploder
 *
 * Converts an Iterator over chunks of data into an Iterator over SQL
 * statements.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class SqlStatementExploder extends SqlStatementParserBase implements \Iterator
{
    /* @var \Iterator $ity */
    private $ity;
    private $firstChunkConsumed;
    private $current;

    /**
     * @param \Iterator $chunk_iterator fetching chunks of bytes to parse
     */
    public function __construct(\Iterator $chunk_iterator)
    {
        parent::__construct();
        $this->ity = $chunk_iterator;
        $this->firstChunkConsumed = false;
        $this->current = '';
    }

    /**
     * @return string the current SQL statement
     */
    public function current()
    {
        return $this->current;
    }

    /**
     * @return int line number of the current statement within the stream
     */
    public function key()
    {
        return $this->curLineNo;
    }

    /**
     * @return void
     * @throws StatementExploderException
     */
    public function next()
    {
        $this->current = $this->fetch();
        assert($this->current !== false);
    }

    /**
     * Rewind the underlying Iterator and fetch the first statement, again.
     *
     * @return void
     * @throws StatementExploderException
     */
    public function rewind()
    {
        $this->ity->rewind();
        if ($this->ity->valid()) {
            $this->firstChunkConsumed = false;
            $this->current = $this->fetch();
            assert($this->current !== false);
        } else {
            $this->buffer = null;
            $this->current = '';
        }
    }

    /**
     * Checks if current position is valid
     *
     * @return bool
     */
    public function valid()
    {
        return $this->current !== '' && $this->current !== false;
    }

    /**
     * @return string|false
     */
    protected function getNextChunk()
    {
        if ($this->firstChunkConsumed) {
            $this->ity->next();
        } else {
            $this->firstChunkConsumed = true;
        }
        if ($this->ity->valid()) {
            return $this->ity->current();
        } else {
            return '';
        }
    }
}
