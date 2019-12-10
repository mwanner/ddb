<?php

namespace Datahouse\Libraries\Database\Tests;

use Datahouse\Libraries\Database\GitInterface;

/**
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class MockGitMissing implements GitInterface
{
    public function getToplevel()
    {
        return null;
    }

    public function populateBlobCache($_unused1, $_unused2)
    {
        return false;
    }

    public function hashInGitHead($_)
    {
        return false;
    }
}
