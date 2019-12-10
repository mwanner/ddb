<?php

namespace Datahouse\Libraries\Database\Logic;

use Datahouse\Libraries\Database\IReporter;

/**
 * Visitor and status reporting class for PrepareCommand.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2018-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class PrepareStatus implements IComparisonResultVisitor
{
    protected $reporter;
    protected $fileNameDisplayFn;
    protected $errors;

    /**
     * @param IReporter $reporter          to use
     * @param callable  $fileNameDisplayFn to display proper relative paths
     */
    public function __construct(
        IReporter $reporter,
        callable $fileNameDisplayFn
    ) {
        $this->reporter = $reporter;
        $this->fileNameDisplayFn = $fileNameDisplayFn;
        $this->errors = 0;
    }

    /**
     * @param string $filename to convert
     * @return string filename to display
     */
    public function displayFileName($filename)
    {
        $displayFn = $this->fileNameDisplayFn;
        return $displayFn($filename);
    }

    /**
     * @return int
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * @param MigrationStep    $step      this result is about
     * @param ValidationResult $result    of validation against files on disk
     * @param bool             $isNewHash compared to the last commit
     * @param bool             $isNewFile compared to the last commit
     * @return void
     * @SuppressWarnings("PMD.UnusedFormalParameter")
     */
    public function visitMatchingStep(
        MigrationStep $step,
        ValidationResult $result,
        $isNewHash,
        $isNewFile
    ) {
    }

    /**
     * @param MigrationStep    $step   this result is about
     * @param ValidationResult $result of validation against files on disk
     * @return void
     * @SuppressWarnings("PMD.UnusedFormalParameter(step)")
     */
    public function visitMissingStep(
        MigrationStep $step,
        ValidationResult $result
    ) {
        $this->reporter->reportStatus(
            '   <error>missing file '
            . $this->displayFileName($result->origFileName)
            . ' referenced from the manifest</>'
        );
        $this->errors += 1;
    }

    /**
     * @param MigrationStep    $step   this result is about
     * @param ValidationResult $result of validation against files on disk
     * @return void
     * @SuppressWarnings("PMD.UnusedFormalParameter(step)")
     */
    public function visitMissingHistoricStep(
        MigrationStep $step,
        ValidationResult $result
    ) {
        $shortHash = $this->reporter->getShortHash($step->filehash);
        $this->reporter->reportStatus(
            "   <error>could not load historic version " . $shortHash
            . ' of ' . $this->displayFileName($result->usedFileName)
            . ' referenced from manifest</>'
        );
        $this->errors += 1;
    }

    /**
     * @param MigrationStep    $step      this result is about
     * @param ValidationResult $result    of validation against files on disk
     * @param bool             $isNewFile compared to the last commit
     * @return void
     * @SuppressWarnings("PMD.UnusedFormalParameter(step)")
     */
    public function visitMismatchingStep(
        MigrationStep $step,
        ValidationResult $result,
        $isNewFile
    ) {
        if ($isNewFile) {
            $this->reporter->reportStatus(
                '   <comment>'
                . $this->displayFileName($result->origFileName)
                . '</>: hash mismatch for new file'
            );
        } else {
            $this->reporter->reportStatus(
                '   <error>hash mismatch for '
                . $this->displayFileName($result->usedFileName)
                . '</> (immutable)'
            );
        }

        $this->errors += 1;
    }

    /**
     * @param MigrationStep    $step      this result is about
     * @param ValidationResult $result    of validation against files on disk
     * @param bool             $isNewFile compared to the last commit
     * @return void
     * @SuppressWarnings("PMD.UnusedFormalParameter(step)")
     */
    public function visitChangedStep(
        MigrationStep $step,
        ValidationResult $result,
        $isNewFile
    ) {
        assert($step->mutable);
    }
}
