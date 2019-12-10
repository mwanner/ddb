<?php

namespace Datahouse\Libraries\Database\Logic;

use Datahouse\Libraries\Database\ProjectDirectory;

/**
 * A helper class performing the comparison between the current manifest and
 * the last committed manifest.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class Comparator
{
    protected $project;
    protected $dbtype;
    protected $variant;

    /**
     * @param ProjectDirectory $project providing a manifest
     * @param string           $dbtype  defining the database to use
     * @param string           $variant to use
     */
    public function __construct(
        ProjectDirectory $project,
        $dbtype,
        $variant
    ) {
        $this->project = $project;
        $this->dbtype = $dbtype;
        $this->variant = $variant;
    }

    /**
     * Invokes the proper method of the visitor for the step and comparison
     * result of the manifest.
     *
     * @param IComparisonResultVisitor $visitor   to use
     * @param ValidationResult         $result    of validation
     * @param MigrationStep            $step      which has been validated
     * @param bool                     $isNewHash not committed
     * @param bool                     $isNewFile not committed
     * @return void
     */
    protected function callVisitor(
        IComparisonResultVisitor $visitor,
        $result,
        $step,
        $isNewHash,
        $isNewFile
    ) {
        switch ($result->status) {
            case ValidationResult::VR_STATUS_MATCHES_CURRENT:
            case ValidationResult::VR_STATUS_MATCHES_HISTORIC:
                $visitor->visitMatchingStep($step, $result, $isNewHash, $isNewFile);
                break;

            case ValidationResult::VR_STATUS_MISSING:
                $visitor->visitMissingStep($step, $result);
                break;

            case ValidationResult::VR_STATUS_MISSING_HISTORIC:
                $visitor->visitMissingHistoricStep($step, $result);
                break;

            case ValidationResult::VR_STATUS_MISMATCH:
                $visitor->visitMismatchingStep($step, $result, $isNewFile);
                break;

            case ValidationResult::VR_STATUS_CHANGED:
                $visitor->visitChangedStep($step, $result, $isNewFile);
                break;

            default:
                assert(false);
                throw new \RuntimeException("Unknown step status.");
        }
    }

    /**
     * Performs a (current) manifest validation and compares the results to
     * the last manifest committed to git.
     *
     * @param IComparisonResultVisitor $visitor for callback per step
     * @return void
     */
    public function compare(IComparisonResultVisitor $visitor)
    {
        $committedManifest = $this->project->loadCommittedManifest(
            $this->dbtype,
            $this->variant
        );
        $currentManifest = $this->project->loadCurrentManifest(
            $this->dbtype,
            $this->variant
        );

        $validationResults = $currentManifest->validate($this->project);
        /* @var ValidationResult $result */
        foreach ($validationResults as $vertexId => $result) {
            $step = $currentManifest->getStep($vertexId);
            if ($this->project->useGit() &&
                $result->usedFileName === $result->origFileName
            ) {
                // Double check if the file and hash is part of the committed
                // manifest.
                if ($committedManifest) {
                    // If within a git repo, check if the migration file is
                    // new or has been committed, already.
                    $hasStep = $committedManifest->hasStepByHash(
                        $result->effectiveHash
                    );
                    $isNewHash = !$hasStep;
                    $gitHash = $this->project->hashInGitHead(
                        $result->origFileName
                    );
                    $isNewFile = $gitHash === false;
                } else {
                    $isNewHash = true;
                    $isNewFile = true;
                }
            } else {
                // If we are not in a git repo, we have nothing to compare
                // against, so isNew and isModified will always equal false.
                $isNewHash = false;
                $isNewFile = false;
            }

            $this->callVisitor($visitor, $result, $step, $isNewHash, $isNewFile);
        }
    }
}
