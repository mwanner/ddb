<?php

namespace Datahouse\Libraries\Database\Logic;

use Datahouse\Libraries\Database\IReporter;

/**
 * Visitor and status reporting class for StatusCommand.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2018-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class StatusStatus implements IComparisonResultVisitor
{
    protected $reporter;
    protected $fileNameDisplayFn;
    protected $infos;
    protected $errors;
    protected $proposeManifestUpdate;
    protected $proposeForcedManifestUpdate;

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
        $this->infos = 0;
        $this->errors = 0;
        $this->proposeManifestUpdate = false;
        $this->proposeForcedManifestUpdate = false;
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
     * @return int
     */
    public function getInfos()
    {
        return $this->infos;
    }

    /**
     * @return bool
     */
    public function needsManifestUpdate()
    {
        return $this->proposeManifestUpdate;
    }

    /**
     * @return bool
     */
    public function needsForcedManifestUpdate()
    {
        return $this->proposeForcedManifestUpdate;
    }

    /**
     * @param MigrationStep    $step      this result is about
     * @param ValidationResult $result    of validation against files on disk
     * @param bool             $isNewHash compared to the last commit
     * @param bool             $isNewFile compared to the last commit
     * @return void
     */
    public function visitMatchingStep(
        MigrationStep $step,
        ValidationResult $result,
        $isNewHash,
        $isNewFile
    ) {
        if ($isNewHash) {
            if ($isNewFile) {
                $status = 'new step';
            } else {
                $status = 'updated ' . ($step->mutable ? '' : 'im') . 'mutable step';
            }
            $this->reporter->reportStatus(
                "   <info>$status</>: "
                . $this->displayFileName($result->origFileName) . '</>'
            );
            $this->infos += 1;
        }
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
     */
    public function visitMissingHistoricStep(
        MigrationStep $step,
        ValidationResult $result
    ) {
        $shortHash = $this->reporter->getShortHash($step->filehash);
        $this->reporter->reportStatus(
            "   <error>missing version " . $shortHash
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
            $this->proposeManifestUpdate = true;
        } else {
            $this->reporter->reportStatus(
                '   <error>hash mismatch for '
                . $this->displayFileName($result->usedFileName)
                . '</> (immutable)'
            );

            $this->proposeForcedManifestUpdate = true;
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
        $this->reporter->reportStatus(
            '   <comment>'
            . $this->displayFileName($result->origFileName)
            . '</>: ' . ($isNewFile ? 'new' : 'existing')
            . ' mutable file has changed'
        );
        $this->proposeManifestUpdate = true;
    }
}
