<?php

namespace Datahouse\Libraries\Database\Exceptions;

/**
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class UserError extends \RuntimeException
{
    private $hint;

    /**
     * UserError constructor.
     * @param string $message describing the error
     * @param string $hint    notifying the user of a possible solution
     */
    public function __construct($message, $hint = '')
    {
        parent::__construct($message);
        $this->hint = $hint;
    }

    /**
     * @return string
     */
    public function getHint()
    {
        return $this->hint;
    }
}
