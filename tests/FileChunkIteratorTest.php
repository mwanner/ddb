<?php

namespace Datahouse\Libraries\Database\Tests;

use Datahouse\Libraries\Database\FileChunkIterator;

/**
 * Class FileChunkIteratorTest
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class FileChunkIteratorTest extends \PHPUnit_Framework_TestCase
{
    public function testSmallChunkSize()
    {
        $fci = new FileChunkIterator(
            dirname(__FILE__) . '/data/sqlfiles/simple.sql',
            8 // only 8 bytes at a time
        );
        $char_count = 0;
        foreach ($fci as $chunk) {
            $char_count += strlen($chunk);
        }
        self::assertEquals(152, $char_count);
    }
}
