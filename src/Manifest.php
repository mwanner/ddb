<?php

namespace Datahouse\Libraries\Database;

use RuntimeException;

use Symfony\Component\Yaml;
use Fhaculty\Graph\Graph as Graph;
use Fhaculty\Graph\Vertex as Vertex;
use Fhaculty\Graph\Edge\Directed as Edge;

use Datahouse\Libraries\Database\Exceptions\UserError;
use Datahouse\Libraries\Database\Logic\AppliedStep;
use Datahouse\Libraries\Database\Logic\MigrationStep;
use Datahouse\Libraries\Database\Logic\ValidationResult;

/**
 * Stores all of the information in a manifest and provides methods to
 * assemble a dependency graph of the individual steps.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class Manifest
{
    private $variantDir;
    private $hash;
    private $version;
    private $name;
    /* @var string[] $roles */
    private $roles;
    /* @var Graph $graph */
    private $graph;
    /* @var Vertex[] $rootVertices those with no parent */
    private $rootVertices;
    /* @val array $labels map of labels to vertex ids, where applicable */
    private $labels;
    private $staticData;
    private $tests;
    private $config;

    /**
     * Manifest constructor.
     *
     * @param string $variantDir containing the manifest
     * @param string $hash       of the manifest contents
     * @param string $version    specified in the manifest
     * @param string $name       specified in the manifest
     */
    public function __construct($variantDir, $hash, $version, $name)
    {
        $this->variantDir = $variantDir;
        $this->hash = $hash;
        $this->version = $version;
        $this->name = $name;
        $this->staticData = [];
        $this->tests = [];
        $this->roles = [];
        $this->graph = null;
        $this->rootVertices = [];
        $this->config = [];
    }

    /**
     * @param string $path     to the manifest
     * @param array  $yamlData parsed YAML data to check
     * @return void
     * @throws UserError
     */
    private static function checkBasicRequirements($path, $yamlData)
    {
        // Some sanity checks on the provided yaml object.
        if (!is_array($yamlData) || array_key_exists(0, $yamlData)) {
            throw new UserError(
                "Manifest data needs to be a YAML mapping"
            );
        }
        try {
            static::checkRequiredFields(
                $yamlData,
                ['version', 'name', 'migrations']
            );
        } catch (UserError $e) {
            throw new UserError("Manifest preamble: " . $e->getMessage());
        }

        if (!is_int($yamlData['version'])) {
            throw new UserError(
                "Manifest $path specifies a non-integer version number."
            );
        }

        if (!is_array($yamlData['migrations'])) {
            throw new UserError(
                "Manifest must specify an array of migration steps."
            );
        }

        if ($yamlData['version'] < 1) {
            throw new UserError(
                "Manifest specifies a clownish version number."
            );
        }
        if ($yamlData['version'] > 1) {
            throw new UserError(
                "Manifest specifies an unknown future version."
            );
        }
    }

    /**
     * @param string $variantDir base for relative paths
     * @param array  $yamlData   parsed YAML data to load from
     * @return void
     */
    private function loadMigrationSteps($variantDir, $yamlData)
    {
        $stepNo = 1;
        $steps = [];
        foreach ($yamlData['migrations'] as $stepDef) {
            $steps[] = $this->readStepDefinition(
                $stepNo,
                $variantDir,
                $stepDef
            );
            $stepNo += 1;
        }
        $this->formMigrationGraph($steps);
    }

    /**
     * @param string $variantDir base for relative paths
     * @param array  $yamlData   parsed YAML data to load from
     * @return void
     * @throws UserError
     */
    private function loadOptionalData($variantDir, $yamlData)
    {
        // This is (still?) optional, certainly in version 1 of the manifest.
        if (array_key_exists('static-data', $yamlData)) {
            if (!is_array($yamlData['static-data'])) {
                throw new UserError(
                    "Manifest's static-data needs to be an array."
                );
            }

            foreach ($yamlData['static-data'] as $idx => $def) {
                $this->readStaticDataDefinition($idx + 1, $variantDir, $def);
            }
        }

        if (array_key_exists('tests', $yamlData)) {
            foreach ($yamlData['tests'] as $path) {
                $this->tests[] = $variantDir . '/' . $path;
            }
        }

        // This basically is a work-around for Postgres' missing IF NOT EXISTS
        // flag for CREATE ROLE.
        if (array_key_exists('roles', $yamlData)) {
            foreach ($yamlData['roles'] as $role) {
                if (is_array($role)) {
                    throw new UserError("Roles must be simple strings.");
                } else {
                    $this->roles[] = strval($role);
                }
            }
        }

        // Parse any database specific configuration.
        if (array_key_exists('config', $yamlData)) {
            $this->config = $yamlData['config'];
        }
    }

    /**
     * @return string (git) hash of the manifest file
     */
    public function getHash()
    {
        return $this->hash;
    }

    /**
     * @return string path of the variant
     */
    public function getVariantDir()
    {
        return $this->variantDir;
    }

    /**
     * @return mixed number of steps in this manifest
     */
    public function getTotalVertexCount()
    {
        return count($this->graph->getVertices());
    }

    /**
     * Lookup a MigrationStep by its vertexId in the graph.
     *
     * @param string $vid to lookup
     * @return MigrationStep
     */
    public function getStep($vid)
    {
        $vertex = $this->graph->getVertex($vid);
        return $vertex->getAttribute('step');
    }

    /**
     * @return string[] test files linked from this manifest
     */
    public function getTests()
    {
        return $this->tests;
    }

    /**
     * @return string[] roles used in this manifest
     */
    public function getRoles()
    {
        return $this->roles;
    }

    /**
     * @return StaticData[]
     */
    public function getStaticData()
    {
        return $this->staticData;
    }

    /**
     * @return array
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * Checks whether or not the given hash is a known manifest step.
     *
     * @param string $hash of the step to look for
     * @return bool
     */
    public function hasStepByHash($hash)
    {
        $result = false;
        $this->visitVerticesDFS(function (Vertex $v) use ($hash, &$result) {
            /* @var MigrationStep $step */
            $step = $v->getAttribute('step');
            if ($step->filehash == $hash) {
                $result = true;
            }
        });
        return $result;
    }

    private static function checkRequiredFields($yaml_data, $req_fields)
    {
        $missing_fields = [];
        foreach ($req_fields as $req_field) {
            if (!is_array($yaml_data)
                || !array_key_exists($req_field, $yaml_data)
            ) {
                $missing_fields[] = $req_field;
            }
        }

        if (count($missing_fields) > 0) {
            throw new UserError(
                "Missing fields: " . implode(", ", $missing_fields)
            );
        }
    }

    /**
     * Load a migration step definition from a manifest file.
     *
     * @param int    $step_no     step index
     * @param string $variant_dir base dir of the variant
     * @param array  $def         from the yaml file
     * @return MigrationStep
     */
    private function readStepDefinition($step_no, $variant_dir, $def)
    {
        try {
            static::checkRequiredFields($def, ['hash', 'path']);
        } catch (UserError $e) {
            throw new UserError("Step $step_no: " . $e->getMessage());
        }
        return new MigrationStep(
            $this,
            // absolute path
            $variant_dir . '/' . $def['path'],
            $def['hash'],
            array_key_exists('mutable', $def) && $def['mutable'],
            array_key_exists('label', $def) ? $def['label'] : '',
            array_key_exists('parents', $def) ? $def['parents'] : null
        );
    }


    /**
     * @param string   $filehash of the step
     * @param string[] $parents  set of direct parents of this step
     * @return string
     */
    private function calcVertexId($filehash, $parents)
    {
        // It's very important these vertex ids are stable, so sort the set
        // of parents.
        sort($parents);
        $desc = "filehash:" . $filehash . "\n"
            . "parents:" . implode(",", $parents);
        // An homage to Bitcoin...
        return hash('sha256', hash('sha256', $desc, true));
    }

    private function formMigrationGraph(array $steps)
    {
        $this->graph = new Graph();
        /* @var Vertex|null $lastVertex */
        $lastVertex = null;
        $missingParents = [];
        $missingLabels = [];
        foreach ($steps as $idx => $step) {
            // Determine parents of this step.
            if (isset($step->parents)) {
                $parents = [];
                foreach ($step->parents as $label) {
                    if (array_key_exists($label, $this->labels)) {
                        $parents[] = $this->labels[$label];
                    } else {
                        $missingLabels[] = $label;
                    }
                }
            } elseif (isset($lastVertex)) {
                $parents = [$lastVertex->getId()];
            } else {
                $parents = [];
            }

            // Assign an internal and unique vertex id to the step: a hash of
            // the parents and the filehash of the step itself. This is unique
            // even when applying the same migration step multiple times (as
            // the parents will change). This effectively creates something
            // similar to a git history.
            $vid = $this->calcVertexId($step->filehash, $parents);

            if (strlen($step->label) >= 0) {
                $this->labels[$step->label] = $vid;
            }

            $vertex = $this->graph->createVertex($vid);
            $vertex->setAttribute('step', $step);
            $vertex->setAttribute('idx', $idx);

            // Add edges from parents to children.
            foreach ($parents as $parentVid) {
                if (!$this->graph->hasVertex($parentVid)) {
                    $missingParents[] = [$step, $parentVid];
                } else {
                    $parent = $this->graph->getVertex($parentVid);
                    $edge = new Edge($parent, $vertex);
                    $this->graph->addEdge($edge);
                }
            }

            // Populate the rootVertices for quick access.
            if (count($parents) == 0) {
                $this->rootVertices[] = $vertex;
            }

            // Store the last vertex used, it's the default parent of the
            // next one.
            $lastVertex = $vertex;
        }

        // FIXME: improve error message
        if (count($missingParents) > 0) {
            throw new UserError("Mismatching labels");
        }
    }

    /**
     * Visit all steps of the manifest in DFS order.
     *
     * @param callable $visitorFn invoked with the vertex
     * @return void
     */
    public function visitVerticesDFS(callable $visitorFn)
    {
        $visited = [];
        // copy the array, so we can modify it w/o corrupting $this.
        $stack = array_slice($this->rootVertices, 0);
        while ($stack) {
            /* @var Vertex $vertex */
            $vertex = array_shift($stack);
            $vid = $vertex->getId();
            $visitorFn($vertex);
            $visited[$vid] = true;
            /* @var Vertex $childVertex */
            foreach ($vertex->getVerticesEdgeTo() as $childVertex) {
                if (!isset($visited[$childVertex->getId()])) {
                    array_unshift($stack, $childVertex);
                }
            }
        }
    }

    private function readStaticDataDefinition($no, $variantDir, $def)
    {
        try {
            static::checkRequiredFields($def, ['table', 'path', 'format']);
        } catch (UserError $e) {
            throw new UserError("Static-data $no: " . $e->getMessage());
        }
        $this->staticData[] = new StaticData(
            $this,
            $def['table'],
            $variantDir . '/' . $def['path'],   // absolute path
            $def['format'],
            isset($def['numPkeyColumns']) ? $def['numPkeyColumns'] : 1
        );
    }

    /**
     * Loads a YAML manifest from a file.
     *
     * @param string $path       to the yaml file to load
     * @param string $variantDir base dir of the variant & manifest
     * @return Manifest loaded
     * @throws UserError
     */
    public static function loadFromFile($path, $variantDir)
    {
        $data = file_get_contents($path);
        if (strlen($data) == 0) {
            throw new UserError("Manifest is empty");
        }

        $parser = new Yaml\Parser();
        $yamlData = $parser->parse($data);
        self::checkBasicRequirements($path, $yamlData);

        $manifest = new Manifest(
            $variantDir,
            static::calcGitBlobHash($data),
            $yamlData['version'],
            $yamlData['name']
        );

        // Further populate the manifest.
        $manifest->loadMigrationSteps($variantDir, $yamlData);
        $manifest->loadOptionalData($variantDir, $yamlData);

        return $manifest;
    }

    /**
     * A hash function emulating the git hash function for any random data.
     *
     * @param string $contents data to hash
     * @return string hex encoded hash
     */
    public static function calcGitBlobHash($contents)
    {
        /*
         * Unfortunately, git doesn't just use a plain hash on the file, but
         * calculates file hashes as follows:
         *
         * Blob Hash = sha1( string("blob") + int(size of file) + byte("\0") +
         *                   <actual contents of the file> )
         *
         * This function calculates a hash using the same formula.
         */
        $contents = str_replace("\r\n", "\n", $contents);
        return hash('sha1', "blob " . strlen($contents). "\0" . $contents);
    }

    /**
     * Checks a sinlge SQL statement and returns true, iff the migration step
     * containing it may be mutable.
     *
     * @param string $orig_stmt the statement to check
     * @return bool
     */
    public static function statementMutable($orig_stmt)
    {
        $stmt = strtoupper(trim($orig_stmt));
        $stmt = preg_replace('/OR\s+REPLACE/', '', $stmt);
        $stmt = preg_replace('/DEFINER\=[^\s]+/', '', $stmt);
        $stmt = preg_replace('/\s+/', ' ', $stmt);
        $words = explode(' ', $stmt, 5);
        return $words[0] == 'SET' ||
               ($words[0] == 'CREATE' &&
                array_key_exists($words[1], Constants::$MUTABLE_OBJECTS));
    }

    /**
     * Validate a manifest against the files on disk by checking the files
     * linked from the manifest and comparing their hashes.
     *
     * @param ProjectDirectory $project directory to validate against
     * @return array<string, ValidationResult> per-step validation result
     */
    public function validate(ProjectDirectory $project)
    {
        $result = [];
        $usedMutableFiles = [];
        /* @var Vertex $vertex */
        foreach ($this->graph->getVertices() as $vertex) {
            /* @var MigrationStep $step */
            $step = $vertex->getAttribute('step');
            $origFileName = $project->getRelativePath($step->filename);
            $usedFileName = $origFileName;

            if (file_exists($step->filename)) {
                if (!is_readable($step->filename)) {
                    throw new RuntimeException(
                        "Cannot read file: '$usedFileName'"
                    );
                }

                // Lookup the file hash from the cache or calculate it.
                if (array_key_exists($step->filename, $usedMutableFiles)) {
                    $cachedInfo = $usedMutableFiles[$step->filename];
                    $effectiveHash = $cachedInfo[0];
                    $step->numStatements = $cachedInfo[3];
                } else {
                    $effectiveHash = static::calcGitBlobHash(
                        file_get_contents($step->filename)
                    );

                    // Enumerate SQL commands in this step
                    $step->numStatements = 0;
                    $sx = new SqlStatementExploder(
                        new FileChunkIterator($step->filename)
                    );
                    $step->numStatements += iterator_count($sx);
                }

                // For mutable steps, we cache some of the info to prevent
                // repeated calculations on the same file.
                if ($step->mutable) {
                    $usedMutableFiles[$step->filename] = [
                        $effectiveHash,
                        $step->filehash,
                        $vertex->getId(),
                        $step->numStatements
                    ];
                }
            } else {
                $effectiveHash = null;
            }

            if ($effectiveHash === $step->filehash) {
                $vr = ValidationResult::VR_STATUS_MATCHES_CURRENT;
            } elseif ($step->mutable) {
                // Search for an alternative, historic variant of this
                // migration step.
                $hasCachedBlob = !is_null($project->getHistoricBlobPath(
                    $this->variantDir,
                    $step->filehash
                )) || $project->fetchGitObject(
                    $this->variantDir,
                    $step->filehash
                );
                if ($hasCachedBlob) {
                    $vr = ValidationResult::VR_STATUS_MATCHES_HISTORIC;
                    $usedFileName = $this->variantDir . '/' . Constants::BLOB_DIR
                        . '/' . $step->filehash;
                } else {
                    $vr = ValidationResult::VR_STATUS_MISSING_HISTORIC;
                }
            } else {
                // An immutable step that doesn't match the hash given in the
                // manifest.
                if (is_null($effectiveHash)) {
                    $vr = ValidationResult::VR_STATUS_MISSING;
                } else {
                    $vr = ValidationResult::VR_STATUS_MISMATCH;
                }
            }

            assert(is_string($vertex->getId()));
            assert(strlen($vertex->getId()) > 0);
            $result[$vertex->getId()] = new ValidationResult(
                $vr,
                $origFileName,
                $usedFileName,
                $step->mutable,
                $effectiveHash,
                $step->filehash
            );
        }

        foreach ($usedMutableFiles as $cachedInfo) {
            list ($effectiveHash, $lastUsedHash, $vertexId, ) = $cachedInfo;
            if ($effectiveHash !== $lastUsedHash) {
                /* @var ValidationResult $vr */
                $vr = $result[$vertexId];
                $vr->status = ValidationResult::VR_STATUS_CHANGED;
            }
        }

        return $result;
    }

    /**
     * Compare this manifest against a list of applied steps (usually from
     * @param ValidationResult[] $validationResults to check
     * @return void
     * @throws UserError
     */
    public function ensureManifestConsistency($validationResults)
    {
        $badSteps = [];
        foreach ($validationResults as /* $vertexId => */ $stepResult) {
            if (in_array($stepResult->status, [
                ValidationResult::VR_STATUS_MISSING,
                ValidationResult::VR_STATUS_MISMATCH,
                ValidationResult::VR_STATUS_MISSING_HISTORIC
            ])) {
                $badSteps[] = $stepResult->origFileName;
            }
        }
        if (count($badSteps) > 0) {
            // FIXME: this displays filenames relative to the project root,
            // rather than relative to the manifest.
            throw new UserError(
                "Inconsistent manifest.",
                "The following step(s) are mismatching or missing:"
                . "\n    "
                . implode("\n    ", $badSteps) . "\n\n"
                . "Please ensure manifest consistency."
            );
        }
    }

    /**
     * Try to determine all vertices covered by records of applied migrations.
     * This is kind of a breath first search keeping track of a horizon of
     * possible steps to take next and following the order of application
     * given.
     *
     * @param AppliedStep[] $appliedSteps as specified by the database
     * @return array
     * @throws RuntimeException
     */
    public function getVertexIdsForAppliedSteps($appliedSteps)
    {
        /* @var string[] $horizon the steps possible to pull next,
         * according to the manifest */
        $horizon = array_flip(array_map(function (Vertex $vertex) {
            return $vertex->getId();
        }, $this->rootVertices));
        $covered = [];
        $unknown = [];
        foreach ($appliedSteps as $appliedStep) {
            $candidates = [];
            foreach (array_keys($horizon) as $vertexId) {
                $vertex = $this->graph->getVertex($vertexId);
                /* @var MigrationStep $step */
                $step = $vertex->getAttribute('step');
                if ($step->filehash == $appliedStep->filehash) {
                    $candidates[] = $vertex;
                }
            }

            if (count($candidates) == 1) {
                $vertex = $candidates[0];
                $covered[$vertex->getId()] = true;
                // Adjust the horizon: consume the current vertex, but add
                // its children as possible next steps to consume.
                unset($horizon[$vertex->getId()]);
                /* @var Edge $edge */
                foreach ($vertex->getEdgesOut() as $edge) {
                    $horizon[$edge->getVertexEnd()->getId()] = true;
                }
            } elseif (count($candidates) == 0) {
                $unknown[] = [
                    'filehash' => $appliedStep->filehash,
                    'horizon' => array_keys($horizon)
                ];
                // Given steps unknown to the manifest were applied to the
                // database, chances for a proper migration are nil. For good
                // error reporting, extend the horizon to include all
                // children of itself.
                foreach (array_keys($horizon) as $vertexId) {
                    $vertex = $this->graph->getVertex($vertexId);
                    foreach ($vertex->getEdgesOut() as $edge) {
                        $horizon[$edge->getVertexEnd()->getId()] = true;
                    }
                }
            } else {
                throw new UserError(
                    "Egad! Not sure what to do in this case.",
                    "Please contact the lazy author of this weird piece of "
                    . "software and show him\nyour fine use case."
                );
            }
        }

        return [$covered, $horizon, $unknown];
    }

    /**
     * Compares this manifest against a list of applied steps (usually from
     * a target database).
     *
     * @param AppliedStep[] $appliedSteps of steps already applied
     * @return ManifestComparisonResult
     */
    public function compareWith(array $appliedSteps)
    {
        list ($covered, $horizon, $unknown)
            = $this->getVertexIdsForAppliedSteps($appliedSteps);

        if (count($unknown) > 0) {
            return new ManifestComparisonResult(
                ManifestComparisonResult::UNABLE_TO_MIGRATE
            );
        } elseif (count($horizon) == 0) {
            return new ManifestComparisonResult(
                ManifestComparisonResult::SATISFIES_TARGETS
            );
        } else {
            // Assemble a proper migration path using DFS search.
            $stack = array_keys($horizon);
            $migrationPath = [];
            while ($stack) {
                $vertexId = array_shift($stack);
                assert(!array_key_exists($vertexId, $covered));
                $vertex = $this->graph->getVertex($vertexId);
                $migrationPath[] = $vertex->getId();

                /* @var Edge $edge */
                foreach ($vertex->getEdgesOut() as $edge) {
                    array_unshift(
                        $stack,
                        $edge->getVertexEnd()->getId()
                    );
                }
            }

            $result = new ManifestComparisonResult(
                ManifestComparisonResult::MIGRATABLE
            );
            $result->addMigrationPath($migrationPath);
            return $result;
        }
    }
}
