<?php

namespace Datahouse\Libraries\Database\Tests;

use Datahouse\Libraries\Database\DataReaders\CsvReader;

/**
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2017-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class CsvReaderTest extends \PHPUnit_Framework_TestCase
{
    public function testReadSimpleCsv()
    {
        $path = __DIR__ . '/data/csv/simple_with_headers.csv';
        $reader = new CsvReader($path);

        self::assertEquals(['id', 'name', 'even'], $reader->getColumnNames());

        $data = iterator_to_array($reader);
        self::assertEquals([
            1 => ['1', 'one', 'false'],
            2 => ['2', 'two', 'true'],
            3 => ['3', 'three', 'false'],
            4 => ['4', 'four', 'true'],
            5 => ['5', 'five', 'false']
        ], $data);
    }

    public function testReadSimpleCsvWithoutHeaders()
    {
        $path = __DIR__ . '/data/csv/simple_without_headers.csv';
        $headers = ['id', 'name'];
        $reader = new CsvReader($path, ",", '"', "\\", $headers);

        self::assertEquals($headers, $reader->getColumnNames());

        $data = iterator_to_array($reader);
        self::assertEquals([
            1 => ['1', 'one'],
            2 => ['2', 'two'],
            3 => ['3', 'three']
        ], $data);
    }
}
