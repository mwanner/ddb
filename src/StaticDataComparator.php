<?php

namespace Datahouse\Libraries\Database;

use RuntimeException;

use Datahouse\Libraries\Database\DataReaders\IRelationalRowIterator;
use Datahouse\Libraries\Database\Driver\BasePdoDriver;
use Datahouse\Libraries\Database\Driver\PdoResultIterator;

/**
 * Class StaticDataComparator
 *
 * Compares two *sorted* sets of rows and calls a visitor for each row,
 * allowing to take action on common rows as well as rows missing on either
 * side.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2017-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class StaticDataComparator
{
    private $drv;
    private $tableName;
    private $staticDataSource;

    /**
     * @param BasePdoDriver          $drv            database driver to query
     * @param string                 $tableName      table name of the db table
     * @param IRelationalRowIterator $ity            for fetching static data
     * @param int                    $numPkeyColumns at the start of the row
     */
    public function __construct(
        BasePdoDriver $drv,
        $tableName,
        IRelationalRowIterator $ity,
        $numPkeyColumns
    ) {
        $this->drv = $drv;
        $this->tableName = $tableName;
        $this->staticDataSource = $ity;
        $this->numPkeyColumns = $numPkeyColumns;
    }

    /**
     * @param array $sdRow row from static data
     * @param array $dbRow row from database
     * @return int
     */
    private function compareRows($sdRow, $dbRow, $startIdx, $endIdx=null)
    {
        if (is_null($endIdx)) {
            $endIdx = max(count($sdRow), count($dbRow));
        }
        for ($i = $startIdx; $i < $endIdx; $i += 1) {
            $sdAttr = isset($sdRow[$i]) ? $sdRow[$i] : null;
            $dbAttr = isset($dbRow[$i]) ? $dbRow[$i] : null;

            if (is_numeric($sdAttr) && is_numeric($dbAttr)) {
                if ($sdAttr < $dbAttr) {
                    return -1;
                } elseif ($sdAttr > $dbAttr) {
                    return 1;
                }
            } else {
                $res = strcmp($sdAttr, $dbAttr);
                if ($res > 0) {
                    return 1;
                } elseif ($res < 0) {
                    return -1;
                }
            }
        }
        return 0;
    }

    /**
     * @param IRowComparisonVisitor $visitor with callbacks for rows
     */
    public function run(IRowComparisonVisitor $visitor)
    {
        $pdo = $this->drv->getPdo();

        $headers = array_map(function ($fieldName) {
            return $fieldName . '::TEXT';
        }, $this->staticDataSource->getColumnNames());

        $fields = implode(', ', $headers);
        $sql = 'SELECT ' . $fields . ' FROM ' . $this->tableName
            . ' ORDER BY ' . $fields;
        $sth = $pdo->prepare($sql, PdoResultIterator::PREPARE_ARGUMENTS);
        $sth->execute();

        $db = new PdoResultIterator($sth);
        $db->rewind();
        $sd = $this->staticDataSource;
        $sd->rewind();

        while (true) {
            if ($sd->valid() && $db->valid()) {
                // compare the two rows' primary keys
                $cmpResult = $this->compareRows(
                    $sd->current(),
                    $db->current(),
                    0,
                    $this->numPkeyColumns
                );
                switch ($cmpResult) {
                    case 0:
                        $cmpRest = $this->compareRows(
                            $sd->current(),
                            $db->current(),
                            $this->numPkeyColumns
                        );
                        if ($cmpRest == 0) {
                            $visitor->handleCommonRow($db->current());
                        } else {
                            $visitor->handleDifference(
                                $db->current(),
                                $sd->current()
                            );
                        }
                        $sd->next();
                        $db->next();
                        continue;
                    case -1:
                        $visitor->handleStaticDataOnlyRow($sd->current());
                        $sd->next();
                        continue;
                    case 1:
                        $visitor->handleDatabaseOnlyRow($db->current());
                        $db->next();
                        continue;
                    default:
                        throw new RuntimeException(
                            "invalid result of row comparison"
                        );

                }
            } elseif ($sd->valid() && !$db->valid()) {
                // row in static sources, but not in the database
                $visitor->handleStaticDataOnlyRow($sd->current());
                $sd->next();
            } elseif (!$sd->valid() && $db->valid()) {
                // row in the database, but not in static sources
                $visitor->handleDatabaseOnlyRow($db->current());
                $db->next();
            } else {
                // both iterators scanned to the end, leave the loop
                break;
            }
        }
    }
}
