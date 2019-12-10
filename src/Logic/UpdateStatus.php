<?php

namespace Datahouse\Libraries\Database\Logic;

use Datahouse\Libraries\Database\IReporter;

/**
 * Visitor used for UpdateCommand's invocation of the Comparator.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2017-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class UpdateStatus implements IComparisonResultVisitor
{
    protected $reporter;
    protected $fileNameDisplayFn;

    /* @var int $errors */
    protected $errors;

    /* @var array $updates */
    protected $updates;

    /* @var bool $forcedUpdate */
    protected $forcedUpdate;

    /**
     * @param IReporter $reporter          to use
     * @param callable  $fileNameDisplayFn to use
     * @param bool      $forcedUpdate      overrides mutability
     */
    public function __construct(
        IReporter $reporter,
        callable $fileNameDisplayFn,
        $forcedUpdate
    ) {
        $this->reporter = $reporter;
        $this->fileNameDisplayFn = $fileNameDisplayFn;
        $this->forcedUpdate = $forcedUpdate;
        $this->errors = 0;
        $this->updates = [];
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
     * @return int number of errors that occurred
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getUpdates()
    {
        return $this->updates;
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
        // no-op
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
            "   <error>missing file "
            . $this->displayFileName($result->origFileName)
            . " referenced from the manifest</>"
        );
        $this->errors += 1;
    }

    /**
     * @param MigrationStep    $step   this result is about
     * @param ValidationResult $result of validation against files on disk
     * @return void
     */
    public function visitMissingHistoricStep(
        MigrationStep $step,
        ValidationResult $result
    ) {
        $shortHash = $this->reporter->getShortHash($step->filehash);
        $this->reporter->reportStatus(
            "   <error>missing version " . $shortHash
            . " of " . $this->displayFileName($result->usedFileName)
            . " referenced from manifest</>"
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
        if ($isNewFile || $this->forcedUpdate) {
            $this->updates[$result->usedFileName] = [
                'optype' => 'update',
                'mutable' => false,
                'filename' => $result->usedFileName,
                'newHash' => $result->effectiveHash,
                'oldHash' => $result->manifestHash,
            ];
        } else {
            $this->reporter->reportStatus(
                "   <error>hash mismatch for "
                . $this->displayFileName($result->usedFileName)
                . " referenced from committed manifest</>"
            );
            $this->errors += 1;
        }
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
        if ($isNewFile) {
            // If the mutable step is new (i.e. not in the former git
            // manifest) anyways, we don't need to re-add it, but can update
            // the hash in place.
            assert($result->usedFileName === $result->origFileName);
            $this->updates[$result->origFileName] = [
                'optype' => 'update',
                'mutable' => true,
                'newHash' => $result->effectiveHash,
                'oldHash' => $result->manifestHash
            ];
        } else {
            $this->updates[$result->origFileName] = [
                'optype' => 'add',
                'mutable' => true,
                'newHash' => $result->effectiveHash
            ];
        }
    }
}
