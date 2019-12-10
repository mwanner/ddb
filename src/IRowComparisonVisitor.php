<?php

namespace Datahouse\Libraries\Database;

/**
 * Interface for visitors called by the StaticDataComparator
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2017-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
interface IRowComparisonVisitor
{
    /**
     * @param array $row that matches between the two sources
     * @return void
     */
    function handleCommonRow($row);

    /**
     * @param array $fromRow as in the database
     * @param array $toRow   as provided
     * @return void
     */
    function handleDifference($fromRow, $toRow);

    /**
     * @param array $row existing only in the database
     * @return void
     */
    function handleDatabaseOnlyRow($row);

    /**
     * @param array $row existing only in the static data source
     * @return void
     */
    function handleStaticDataOnlyRow($row);
}
