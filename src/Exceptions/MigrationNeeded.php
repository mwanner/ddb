<?php

namespace Datahouse\Libraries\Database\Exceptions;

/**
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class MigrationNeeded extends \Exception
{
    /**
     * MigrationNeeded constructor.
     */
    public function __construct()
    {
        parent::__construct("db migration needed");
    }
}