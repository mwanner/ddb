<?php

namespace Datahouse\Libraries\Database;

/**
 * Class ChunkedFileReader
 *
 * Provides an interable interface to a readable file.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class FileChunkIterator implements \Iterator
{
    private $path;
    private $fp;
    private $buffer;

    public function __construct($path, $chunk_size = 8192)
    {
        $this->path = $path;
        $this->chunk_size = $chunk_size;
        $this->fp = null;
        $this->buffer = null;
    }

    private function open()
    {
        assert(is_null($this->fp));
        $this->fp = @fopen($this->path, 'r');
        if (!$this->fp) {
            throw new \RuntimeException("Unable to open file '$this->path'");
        }
        $this->next();
    }

    private function close()
    {
        assert(isset($this->fp));
        @fclose($this->fp);
        $this->fp = null;
    }

    public function current()
    {
        return $this->buffer;
    }

    public function key()
    {
        assert(false);
    }

    public function next()
    {
        if (isset($this->fp)) {
            $this->buffer = fgets($this->fp, $this->chunk_size);
            if ($this->buffer === false) {
                if (feof($this->fp)) {
                    $this->buffer = null;
                    $this->close();
                } else {
                    throw new \RuntimeException(
                        "Error reading from file '$this->path'"
                    );
                }
            }
        } else {
            $this->buffer = null;
        }
    }

    public function rewind()
    {
        if (isset($this->fp)) {
            $this->close();
        }
        $this->open();
    }

    public function valid()
    {
        return isset($this->fp) || isset($this->buffer);
    }
}
