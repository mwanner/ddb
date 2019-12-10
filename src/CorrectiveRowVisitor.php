<?php

namespace Datahouse\Libraries\Database;

use PDOStatement;
use RuntimeException;

/**
 * A helper for the MigrateCommand adapting the database to static data.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2017-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class CorrectiveRowVisitor implements IRowComparisonVisitor
{
    /* @var string[] $paramNames */
    private $paramNames;
    private $insert;
    private $update;
    private $delete;
    private $numPkeyColumns;

    public function __construct(
        $paramNames,
        PDOStatement $insertStmt,
        PDOStatement $updateStmt,
        PDOStatement $deleteStmt,
        $numPkeyColumns
    ) {
        $this->paramNames = $paramNames;
        $this->insert = $insertStmt;
        $this->update = $updateStmt;
        $this->delete = $deleteStmt;
        $this->numPkeyColumns = $numPkeyColumns;
    }

    /**
     * @param array $row
     * @return void
     */
    public function handleCommonRow($row)
    {
        // no-op
    }

    /**
     * @param array $row
     * @return void
     */
    public function handleDatabaseOnlyRow($row)
    {
        $this->delete->execute(array_combine(
            array_slice($this->paramNames, 0, $this->numPkeyColumns),
            array_slice($row, 0, $this->numPkeyColumns)
        ));
    }

    /**
     * @param array $row
     * @return void
     */
    public function handleStaticDataOnlyRow($row)
    {
        $this->insert->execute(array_combine($this->paramNames, $row));
    }

    /**
     * @param array $fromRow as in the database
     * @param array $toRow   as provided
     * @return void
     */
    public function handleDifference($fromRow, $toRow)
    {
        $rowsAffected = $this->update->execute(
            array_combine($this->paramNames, $toRow)
        );
        if ($rowsAffected == 0) {
            throw new RuntimeException("no row updated");
        } elseif ($rowsAffected > 1) {
            throw new RuntimeException("more than one row updated");
        }
    }
}
