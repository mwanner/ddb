<?php

namespace Datahouse\Libraries\Database\Exceptions;

/**
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2018-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class MissingSuperuserConnInfo extends UserError
{
    public function __construct($serverDesc)
    {
        parent::__construct(
            "Missing superuser connection info for: " . $serverDesc,
            "Try adding a connection definition with 'superuser: true' "
                . "for that server"
        );
    }
}
