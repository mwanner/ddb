<?php

namespace Datahouse\Libraries\Database;

use Datahouse\Libraries\Database\Exceptions\StatementExploderException;
use Evenement\EventEmitterTrait;
use React\Stream\WritableStreamInterface;

/**
 * Class SqlStatementEmitter
 *
 * Converts a ReadableStreamInterface (from ReactPHP) into a stream of
 * individual SQL statements. Especially useful for piping process outputs
 * to the SqlStatementParser. Emits events of type 'statement'.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class SqlStatementEmitter extends SqlStatementParserBase implements WritableStreamInterface
{
    use EventEmitterTrait;

    protected $terminated = false;
    protected $bufferedChunks = [];

    /**
     * @return bool
     */
    public function isWritable()
    {
        return !$this->terminated && empty($this->bufferedChunks);
    }

    /**
     * @param mixed|string $chunk to process
     * @return bool
     * @throws StatementExploderException
     */
    public function write($chunk)
    {
        $this->bufferedChunks[] = $chunk;
        $this->processBuffer();
        return true;
    }

    /**
     * @param mixed|string|null $chunk to process
     * @return void
     * @throws StatementExploderException
     */
    public function end($chunk = null)
    {
        if (isset($chunk)) {
            $this->write($chunk);
        }
        $this->terminated = true;
        $this->emit('end');
    }

    /**
     * @return void
     */
    public function close()
    {
        $this->terminated = true;
        $this->emit('close');
        $this->removeAllListeners();
    }

    /**
     * @return void
     * @throws StatementExploderException
     */
    public function processBuffer()
    {
        for (;;) {
            $statement = $this->fetch();
            if ($statement === false) {
                break;
            }
            $this->emit('statement', [$statement]);
        }
    }

    /**
     * @return string|false
     */
    public function getNextChunk()
    {
        if (!empty($this->bufferedChunks)) {
            return array_shift($this->bufferedChunks);
        } elseif ($this->terminated) {
            return '';
        } else {
            return false;
        }
    }
}
