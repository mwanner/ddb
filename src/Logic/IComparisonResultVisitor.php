<?php

namespace Datahouse\Libraries\Database\Logic;

/**
 * Interface IComparisonResultVisitor used by the Comparator
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2018-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
interface IComparisonResultVisitor
{
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
    );

    /**
     * @param MigrationStep    $step   this result is about
     * @param ValidationResult $result of validation against files on disk
     * @return void
     */
    public function visitMissingStep(
        MigrationStep $step,
        ValidationResult $result
    );

    /**
     * @param MigrationStep    $step   this result is about
     * @param ValidationResult $result of validation against files on disk
     * @return void
     */
    public function visitMissingHistoricStep(
        MigrationStep $step,
        ValidationResult $result
    );

    /**
     * @param MigrationStep    $step      this result is about
     * @param ValidationResult $result    of validation against files on disk
     * @param bool             $isNewFile compared to the last commit
     * @return void
     */
    public function visitMismatchingStep(
        MigrationStep $step,
        ValidationResult $result,
        $isNewFile
    );

    /**
     * @param MigrationStep    $step          this result is about
     * @param ValidationResult $result        compared to files on disk
     * @param bool             $isNewFileFile compared to the last commit
     * @return void
     */
    public function visitChangedStep(
        MigrationStep $step,
        ValidationResult $result,
        $isNewFileFile
    );
}
