<?php

namespace Datahouse\Libraries\Database\DataReaders;

use Datahouse\Libraries\Database\Exceptions\UserError;

/**
 * Class CsvReader
 *
 * A wrapper over PHP's CSV reading methods providing a sane iterator
 * interface.
 *
 * Note that this loader requires a valid CSV header line.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2017-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class CsvReader implements IRelationalRowIterator
{
    private $fh;
    private $rowNumber;
    private $delimiter;
    private $enclosure;
    private $escape;
    /* @var bool whether or not the CSV sports a header row */
    private $fileHasHeader;
    private $header;
    /* @var array|bool $currentRow */
    private $currentRow;

    /**
     * @param string $path               to the source file
     * @param string $delimiter          delimiter, often a comma, surprise!
     * @param string $enclosure          enclosure char, passed on to fgetcsv
     * @param string $escape             escape char, passed on to fgetcsv
     * @param array|null $implicitHeader to manually define the CSV header
     */
    public function __construct(
        $path,
        $delimiter = ",",
        $enclosure = '"',
        $escape = "\\",
        $implicitHeader = null
    ) {
        $this->delimiter = $delimiter;
        $this->enclosure = $enclosure;
        $this->escape = $escape;

        $this->fh = fopen($path, 'r');
        if ($this->fh === false) {
            throw new UserError("Cannot read file $path");
        }

        if (isset($implicitHeader)) {
            assert(is_array($implicitHeader));
            $this->fileHasHeader = false;
            $this->header = $implicitHeader;
        } else {
            // explicit header in the CSV
            $this->fileHasHeader = true;
            $this->header = $this->fetchRow();
            if (!isset($this->header) || !is_array($this->header)) {
                throw new \RuntimeException("failed reading header from CSV");
            }
        }

        $this->rowNumber = 0;
        $this->currentRow = false;
    }

    /**
     * Closes the open file handle.
     */
    public function __destruct()
    {
        if (isset($this->fh) && $this->fh !== false) {
            fclose($this->fh);
        }
    }

    /**
     * @return array
     */
    private function fetchRow()
    {
        return fgetcsv(
            $this->fh,
            0,   // buffer size, 0 for unlimited since PHP 5.1.0
            $this->delimiter,
            $this->enclosure,
            $this->escape
        );
    }

    /**
     * Returns the column names as per the CSV header line (which is required
     * for this interface).
     *
     * @return string[]
     */
    public function getColumnNames()
    {
        return $this->header;
    }

    /**
     * Return the current row from the CSV.
     * @return array
     */
    public function current()
    {
        assert($this->valid());
        return $this->currentRow;
    }

    /**
     * Move forward to next element
     * @return void
     */
    public function next()
    {
        $this->currentRow = $this->fetchRow();
        $this->rowNumber += 1;
    }

    /**
     * Return the key of the current element, in the CVS case the row number.
     *
     * @return int row number, starting at 1 for the first data row (after
     *             an any header, if present)
     */
    public function key()
    {
        return $this->rowNumber;
    }

    /**
     * Checks if current position is valid
     * @return boolean
     */
    public function valid()
    {
        return $this->currentRow !== false;
    }

    /**
     * Rewind the Iterator to the first data row of the CSV file.
     * @return void
     */
    public function rewind()
    {
        if ($this->rowNumber > 0) {
            rewind($this->fh);
            // skip the header, if present
            if ($this->fileHasHeader) {
                $this->fetchRow();
            }
        }
        $this->currentRow = $this->fetchRow();
        $this->rowNumber = 1;
    }
}
