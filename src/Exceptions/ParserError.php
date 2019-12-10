<?php

namespace Datahouse\Libraries\Database\Exceptions;

/**
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2018-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class ParserError extends \RuntimeException
{
    private $line_no;

    public function __construct($path, $lineNo, $message)
    {
        parent::__construct("Parser Error: " . $message
            . " (" . $path . ", line " . $lineNo . ")");
        $this->line_no = $lineNo;
    }

    public function getLineNo()
    {
        return $this->line_no;
    }
}
