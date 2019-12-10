<?php

namespace Datahouse\Libraries\Database\Exceptions;

use \Exception;

/**
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2018-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class StatementExploderException extends Exception
{
    private $lineNo;

    public function __construct($lineNo, $msg)
    {
        $this->lineNo = $lineNo;
        parent::__construct("parser erorr at line $lineNo: $msg");
    }
}
