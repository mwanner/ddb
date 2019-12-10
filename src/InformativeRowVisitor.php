<?php

namespace Datahouse\Libraries\Database;

/**
 * A helper for the StatusCommand, for counting and reporting
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2017-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class InformativeRowVisitor implements IRowComparisonVisitor
{
    public $counters;

    public function __construct()
    {
        $this->counters = [
            'common' => 0,
            'to_insert' => 0,
            'to_delete' => 0,
            'to_update' => 0
        ];
    }

    /**
     * @param array $row
     * @return void
     */
    public function handleCommonRow($row)
    {
        $this->counters['common'] += 1;
    }

    /**
     * @param array $row
     * @return void
     */
    public function handleDatabaseOnlyRow($row)
    {
        $this->counters['to_delete'] += 1;
    }

    /**
     * @param array $row
     * @return void
     */
    public function handleStaticDataOnlyRow($row)
    {
        $this->counters['to_insert'] += 1;
    }

    /**
     * @param array $fromRow as in the database
     * @param array $toRow   as provided
     * @return void
     */
    public function handleDifference($fromRow, $toRow)
    {
        $this->counters['to_update'] += 1;
    }
}
