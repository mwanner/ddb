<?php

namespace Datahouse\Libraries\Database\DataReaders;

use Iterator;

/**
 * Interface IRelationalRowIterator
 *
 * A very minor extension of PHP's Iterator: these Iterators are expected to
 * iterate over the rows of a relation and know their attribute names.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2017-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
interface IRelationalRowIterator extends Iterator
{
    /**
     * @return string[] array of attribute names
     */
    function getColumnNames();
}
