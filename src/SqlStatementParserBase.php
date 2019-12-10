<?php

namespace Datahouse\Libraries\Database;

use Datahouse\Libraries\Database\Exceptions\StatementExploderException;
use Datahouse\Libraries\Database\Exceptions\StatementExploderSourceNotReady;

/**
 * Class SqlStatementParserBase
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
abstract class SqlStatementParserBase
{
    const ST_DEFAULT = 1;

    const ST_IN_STATEMENT = 20;
    const ST_MAYBE_END_STATEMENT = 21;

    /* special states */
    const ST_MAYBE_START_DOLLARQUOTE = 53;   // Postgres' dollarquote
    const ST_IN_REDEFINE_DELIMITER = 59;     // MySQL's delimiter thingie

    /* string states */
    const ST_IN_STRING = 110;
    const ST_IN_DOUBLEQUOTE_STRING = 111;

    /* dollarquote support for Postgres */
    const ST_IN_DOLLARQUOTE_TAG = 224;
    const ST_IN_DOLLARQUOTE = 225;
    const ST_MAYBE_END_DOLLARQUOTE = 226;

    /* comment states */
    const ST_IN_EOL_COMMENT = 903;
    /* C-style comments for MySQL */
    const ST_IN_CSTYLE_COMMENT = 918;

    private $curStmt;
    /* int $lastWordStartOffset within the curStmt */
    private $lastWordStartOffset;
    protected $curLineNo;
    private $delimiter;
    private $curChar;
    private $nextChar;
    /* @var bool $eos reached end of stream */
    private $eos;
    /* @var string $tag */
    private $tag;

    private $lineNo;
    protected $buffer;
    protected $offset;
    private $fully_consumed;

    private $status;
    private $prevStatus;

    /**
     * SqlStatementParserBase constructor.
     */
    public function __construct()
    {
        $this->reset();
    }

    /**
     * Reset the parser
     *
     * @return void
     */
    public function reset()
    {
        $this->curChar = '';
        $this->nextChar = null;
        $this->eos = false;
        $this->curStmt = '';
        $this->lastWordStartOffset = 0;
        $this->curLineNo = 1;
        $this->delimiter = ';';
        $this->tag = '';

        $this->lineNo = 1;
        $this->buffer = null;
        $this->offset = null;
        $this->fully_consumed = false;

        $this->status = static::ST_DEFAULT;
        $this->prevStatus = null;
    }

    /**
     * @param int $newStatus to set
     * @return void
     */
    public function eatSetAndAdvance($newStatus)
    {
        $this->prevStatus = $this->status;
        $this->status = $newStatus;
        $this->curStmt .= $this->curChar;
        $this->advance();
    }

    /**
     * @param int $newStatus to set
     * @return void
     */
    public function doubleEatSetAndAdvance($newStatus)
    {
        $this->prevStatus = $this->status;
        $this->status = $newStatus;
        $this->curStmt .= $this->curChar;
        $this->advance();
        $this->curStmt .= $this->curChar;
    }

    /**
     * @return void
     */
    public function eatAndAdvance()
    {
        $this->curStmt .= $this->curChar;
        $this->advance();
    }

    /**
     * @return string|false
     */
    abstract protected function getNextChunk();

    /**
     * Ensure the buffer has at least two more bytes to read. Or is at the
     * end of the input stream.
     *
     * @return void
     * @throws StatementExploderSourceNotReady
     */
    public function maybePopulateBuffer()
    {
        if (!$this->eos && $this->offset + 2 >= strlen($this->buffer)) {
            $chunk = $this->getNextChunk();
            if ($chunk === false) {
                // Cannot currently retrieve data.
                throw new StatementExploderSourceNotReady();
            }

            if (strlen($chunk) === 0) {
                $this->eos = true;
            } else {
                $remainder = substr($this->buffer, $this->offset);
                $this->buffer = $remainder . $chunk;
                $this->offset = 0;
            }
        }
    }

    /**
     * Advance to the next character from the input iterator.
     *
     * @return void
     */
    public function advance()
    {
        if (!is_null(($this->nextChar))) {
            $this->curChar = $this->nextChar;
            if ($this->offset < strlen($this->buffer)) {
                $this->nextChar = $this->buffer[$this->offset];
            } else {
                assert($this->eos);
                $this->nextChar = null;
            }
            $this->offset += 1;
        } elseif (!$this->eos) {
            $this->curChar = $this->buffer[$this->offset];
            $this->nextChar = $this->buffer[$this->offset + 1];
            $this->offset += 2;
        } else {
            $this->curChar = null;
        }
    }

    /**
     * @return bool if the curChar has been consumed
     */
    protected function consumeNewline()
    {
        $consumed = false;
        if ($this->curChar === "\n") {
            $this->lineNo += 1;
            if ($this->isStatus(SqlStatementParserBase::ST_DEFAULT)) {
                $this->curLineNo = $this->lineNo;
                $this->curStmt = '';
                $consumed = true;
            } elseif ($this->isStatus(SqlStatementParserBase::ST_IN_EOL_COMMENT)) {
                assert(isset($this->prevStatus));
                $this->status = $this->prevStatus;
                $this->prevStatus = null;
                if ($this->isStatus(SqlStatementParserBase::ST_IN_STATEMENT)) {
                    // keep comments within statements, i.e. procedures
                    $this->curStmt .= $this->curChar;
                } elseif ($this->isStatus(SqlStatementParserBase::ST_DEFAULT)) {
                    $this->curStmt = '';
                    $this->curLineNo = $this->lineNo;
                } else {
                    assert(false);
                }
                $consumed = true;
            }
        }
        return $consumed;
    }

    /**
     * @param int $cmp state to compare against
     * @return bool if the current state matches
     */
    protected function isStatus($cmp)
    {
        return $this->status == $cmp;
    }

    /**
     * Handle the default state.
     *
     * @return void
     */
    protected function handleDefaultState()
    {
        if ($this->curChar === '-' && $this->nextChar === '-') {
            $this->doubleEatSetAndAdvance(SqlStatementParserBase::ST_IN_EOL_COMMENT);
        } elseif ($this->curChar === '/' && $this->nextChar === '*') {
            $this->doubleEatSetAndAdvance(SqlStatementParserBase::ST_IN_CSTYLE_COMMENT);
        } elseif (!ctype_space($this->curChar)) {
            $this->curLineNo = $this->lineNo;
            $this->status = SqlStatementParserBase::ST_IN_STATEMENT;
            $this->lastWordStartOffset = strlen($this->curStmt);
            $this->curStmt .= $this->curChar;
        } else {
            // no-op
            $this->curChar = '';
        }
    }

    /**
     * Process a char in the ST_IN_STATEMENT or ST_MAYBE_END_STATEMENT case.
     *
     * @return bool true if a statement has been completed
     */
    protected function processCharInStatement()
    {
        $nc = $this->nextChar;
        $doBreak = false;
        if ($this->isStatus(SqlStatementParserBase::ST_MAYBE_END_STATEMENT)) {
            $lastWord = substr($this->curStmt, $this->lastWordStartOffset)
                . $this->curChar;
            if ($lastWord === $this->delimiter) {
                // matched a multi-char delimiter, strip the start of
                // the delimiter from the end of the statement.
                $this->curStmt = substr(
                    $this->curStmt,
                    0,
                    $this->lastWordStartOffset
                );
                $doBreak = true;
            } elseif ($lastWord !== substr($this->delimiter, 0, strlen($lastWord))) {
                // Not matching the delimiter, anymore. Reset to
                // ST_IN_STATEMENT.
                $this->status = SqlStatementParserBase::ST_IN_STATEMENT;
            }
        }

        if ($this->curChar == "'") {
            $this->status = SqlStatementParserBase::ST_IN_STRING;
        } elseif ($this->curChar == '"') {
            $this->status = SqlStatementParserBase::ST_IN_DOUBLEQUOTE_STRING;
        } elseif ($this->curChar == '$' && $nc == '$') {
            $this->tag = '';
            $this->eatSetAndAdvance(SqlStatementParserBase::ST_IN_DOLLARQUOTE);
            $this->lastWordStartOffset = strlen($this->curStmt) + 1;
        } elseif (!ctype_alnum($this->curChar) && $nc == '$') {
            $this->status = SqlStatementParserBase::ST_MAYBE_START_DOLLARQUOTE;
            $this->tag = '';
        } elseif ($this->curChar == '-' && $nc == '-') {
            $this->eatSetAndAdvance(SqlStatementParserBase::ST_IN_EOL_COMMENT);
        } elseif ($this->curChar == '/' && $nc == '*') {
            $this->eatSetAndAdvance(SqlStatementParserBase::ST_IN_CSTYLE_COMMENT);
        } elseif (ctype_space($this->curChar)) {
            // Catch MySQL's delimiter command
            $lastWord = substr($this->curStmt, $this->lastWordStartOffset);
            if (strtolower($lastWord) === 'delimiter') {
                $stmtFirstWord = substr($this->curStmt, 0, 9);
                if (strtolower($stmtFirstWord) === 'delimiter') {
                    $this->status = SqlStatementParserBase::ST_IN_REDEFINE_DELIMITER;
                    $this->delimiter = '';
                }
            }
            $this->lastWordStartOffset = strlen($this->curStmt) + 1;
        } elseif ($this->curChar === $this->delimiter[0]) {
            if (strlen($this->delimiter) == 1) {
                // we consume the single delimiter char, here
                $doBreak = true;
            } else {
                // multi-char delimiters
                $this->lastWordStartOffset = strlen($this->curStmt);
                $this->status = SqlStatementParserBase::ST_MAYBE_END_STATEMENT;
            }
        }
        return $doBreak;
    }

    /**
     * Handle special states.
     *
     * @return void
     */
    protected function handleSpecialStates()
    {
        if ($this->isStatus(SqlStatementParserBase::ST_MAYBE_START_DOLLARQUOTE)) {
            if ($this->curChar === '$' && $this->nextChar === '$') {
                $this->doubleEatSetAndAdvance(SqlStatementParserBase::ST_IN_DOLLARQUOTE);
            } elseif ($this->curChar === '$' && ctype_alnum($this->nextChar)) {
                $this->tag = $this->nextChar;
                $this->doubleEatSetAndAdvance(SqlStatementParserBase::ST_IN_DOLLARQUOTE_TAG);
            } else {
                $this->status = SqlStatementParserBase::ST_IN_STATEMENT;
                $this->curStmt .= $this->curChar;
            }
        } elseif ($this->isStatus(SqlStatementParserBase::ST_IN_REDEFINE_DELIMITER)) {
            if ($this->curChar === "\n") {
                // consume the delimiter statement and the newline
                $this->status = SqlStatementParserBase::ST_DEFAULT;
                $this->curLineNo = $this->lineNo;
                $this->curStmt = '';
                $this->curChar = '';
            } elseif (ctype_space($this->curChar) && strlen($this->delimiter) == 0) {
                // ignore spaces between 'DELIMITER' and the actual
                // delimiter to be defined.
            } else {
                $this->delimiter .= $this->curChar;
            }
        } else {
            throw new \LogicException(
                "invalid state $this->status in SqlStatementExploder"
            );
        }
    }

    /**
     * Handle string related states.
     *
     * @return void
     */
    protected function handleStringStates()
    {
        if ($this->isStatus(SqlStatementParserBase::ST_IN_STRING)) {
            if ($this->curChar == "'") {
                $this->status = SqlStatementParserBase::ST_IN_STATEMENT;
            } elseif ($this->curChar == "\\") {
                $this->eatAndAdvance();
            }
            $this->curStmt .= $this->curChar;
        } elseif ($this->isStatus(SqlStatementParserBase::ST_IN_DOUBLEQUOTE_STRING)) {
            if ($this->curChar == '"') {
                $this->status = SqlStatementParserBase::ST_IN_STATEMENT;
            } elseif ($this->curChar == "\\") {
                $this->eatAndAdvance();
            }
            $this->curStmt .= $this->curChar;
        } else {
            throw new \LogicException(
                "invalid state $this->status in SqlStatementExploder"
            );
        }
    }

    /**
     * Handle Postgres Dollarquote states
     *
     * @return void
     * @throws StatementExploderException
     */
    protected function handleDollarquoteStates()
    {
        if ($this->isStatus(SqlStatementParserBase::ST_IN_DOLLARQUOTE_TAG)) {
            if ($this->curChar == '$') {
                $this->status = SqlStatementParserBase::ST_IN_DOLLARQUOTE;
                $this->curStmt .= $this->curChar;
            } elseif (ctype_alnum($this->curChar)) {
                $this->tag .= $this->curChar;
                $this->curStmt .= $this->curChar;
            } else {
                throw new StatementExploderException(
                    $this->curLineNo,
                    "Invalid character for dollar-quote tag: "
                    . ord($this->curChar)
                );
            }
        } elseif ($this->isStatus(SqlStatementParserBase::ST_IN_DOLLARQUOTE)) {
            if ($this->curChar === '$'
                && $this->nextChar === '$'
                && strlen($this->tag) === 0
            ) {
                $this->doubleEatSetAndAdvance(SqlStatementParserBase::ST_IN_STATEMENT);
            } elseif ($this->curChar === '$' && strlen($this->tag) > 0) {
                $this->status = SqlStatementParserBase::ST_MAYBE_END_DOLLARQUOTE;
                $this->lastWordStartOffset = strlen($this->curStmt) + 1;
                $this->curStmt .= $this->curChar;
            } else {
                $this->curStmt .= $this->curChar;
            }
        } elseif ($this->isStatus(SqlStatementParserBase::ST_MAYBE_END_DOLLARQUOTE)) {
            $lastWord = substr($this->curStmt, $this->lastWordStartOffset)
                . $this->curChar;
            if ($this->nextChar === '$' && $lastWord === $this->tag) {
                $this->tag = null;
                $this->doubleEatSetAndAdvance(SqlStatementParserBase::ST_IN_STATEMENT);
            } elseif ($lastWord != substr($this->tag, 0, strlen($lastWord))) {
                // not an ending tag, switch back to IN_DOLLARQUOTE
                $this->status = SqlStatementParserBase::ST_IN_DOLLARQUOTE;
                $this->curStmt .= $this->curChar;
            } else {
                $this->curStmt .= $this->curChar;
            }
        } else {
            throw new \LogicException(
                "invalid state $this->status in SqlStatementExploder"
            );
        }
    }

    /**
     * Handle comment related states.
     *
     * @return void
     */
    protected function handleCommentStates()
    {
        if ($this->isStatus(SqlStatementParserBase::ST_IN_EOL_COMMENT)) {
            // add chars to the current statement, might get discarded
            // later on if we're not in a statement.
            $this->curStmt .= $this->curChar;
        } elseif ($this->isStatus(SqlStatementParserBase::ST_IN_CSTYLE_COMMENT)) {
            if ($this->curChar === '*' && $this->nextChar == '/') {
                // As CSTYLE comments in MySQL contain statements,
                // these may even start a statement.
                $this->doubleEatSetAndAdvance(SqlStatementParserBase::ST_IN_STATEMENT);
            } else {
                $this->curStmt .= $this->curChar;
            }
        } else {
            throw new \LogicException(
                "invalid state $this->status in SqlStatementExploder"
            );
        }
    }

    /**
     * @return void
     * @throws StatementExploderException
     */
    protected function finalize()
    {
        switch ($this->status) {
            case SqlStatementParserBase::ST_DEFAULT:
            case SqlStatementParserBase::ST_IN_EOL_COMMENT:
            case SqlStatementParserBase::ST_IN_STATEMENT:
            case SqlStatementParserBase::ST_MAYBE_END_STATEMENT:
            case SqlStatementParserBase::ST_MAYBE_START_DOLLARQUOTE:
                // These are just fine terminal states. Note that
                // statements are implicitly
                break;

            case SqlStatementParserBase::ST_IN_REDEFINE_DELIMITER:
                // consume the delimiter statement at end of file
                $this->curStmt = '';
                break;

            case SqlStatementParserBase::ST_IN_STRING:
                throw new StatementExploderException(
                    $this->curLineNo,
                    "Unexpected end of file in string"
                );

            case SqlStatementParserBase::ST_IN_DOLLARQUOTE_TAG:
                throw new StatementExploderException(
                    $this->curLineNo,
                    "Unexpected end of file in dollar-quote tag"
                );
            case SqlStatementParserBase::ST_IN_DOLLARQUOTE:
            case SqlStatementParserBase::ST_MAYBE_END_DOLLARQUOTE:
                throw new StatementExploderException(
                    $this->curLineNo,
                    "Unexpected end of file in dollar-quoted string"
                );

            case SqlStatementParserBase::ST_IN_CSTYLE_COMMENT:
                throw new StatementExploderException(
                    $this->curLineNo,
                    "Unexpected end of file in C-style comment"
                );

            default:
                throw new StatementExploderException(
                    $this->curLineNo,
                    "Unknown state at end of file"
                );
        }
    }

    /**
     * The actual state machine of the statement parser.
     *
     * @return bool if a statement has completed and fetch can return it
     * @throws StatementExploderException
     */
    protected function processStates()
    {
        if ($this->status < 100) {
            if ($this->status < 20) {
                $this->handleDefaultState();
            } elseif ($this->status < 50) {
                if ($this->processCharInStatement()) {
                    return true;
                } else {
                    $this->curStmt .= $this->curChar;
                }
            } else {
                $this->handleSpecialStates();
            }
        } else {
            if ($this->status < 200) {
                $this->handleStringStates();
            } elseif ($this->status < 900) {
                $this->handleDollarquoteStates();
            } else {
                $this->handleCommentStates();
            }
        }
        return false;
    }

    /**
     * @return string|false statement of false, if waiting for data
     * @throws StatementExploderException
     */
    public function fetch()
    {
        try {
            for (;;) {
                $this->maybePopulateBuffer();
                $this->advance();
                if ($this->eos && is_null($this->curChar)) {
                    $this->finalize();
                    break;
                }

                assert(strlen($this->curChar) === 1);

                // To keep track of the line count, newlines
                if ($this->consumeNewline()) {
                    continue;
                }

                // Process input data depending on the current state
                if ($this->processStates()) {
                    break;
                }
            }
        } catch (StatementExploderSourceNotReady $e) {
            return false;
        }

        $result = $this->curStmt;
        $this->status = SqlStatementParserBase::ST_DEFAULT;
        $this->prevStatus = null;
        $this->curStmt = '';
        $this->lastWordStartOffset = 0;
        $this->tag = null; // for dollar quotes of Postgres
        return $result;
    }
}
