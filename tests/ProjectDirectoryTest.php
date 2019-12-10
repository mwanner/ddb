<?php

namespace Datahouse\Libraries\Database\Tests;

use Dice\Dice as Dice;
use Fhaculty\Graph\Vertex;

use Datahouse\Libraries\Database\Manifest;
use Datahouse\Libraries\Database\ProjectDirectory;
use Datahouse\Libraries\Database\Logic\ValidationResult;

/**
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class ProjectDirectoryTest extends \PHPUnit_Framework_TestCase
{
    /* @var Dice $dice */
    private $dice;

    /**
     * @return void
     */
    public function setUp()
    {
        $this->dice = new Dice();
    }

    public function testProjectDirRecognition()
    {
        self::assertTrue(
            ProjectDirectory::isProjectRoot(__DIR__ . '/data/p1'),
            "tests/data/p1 not recognized as project directory"
        );
    }

    public function testLocateProjectByTraversal()
    {
        /* @var ProjectDirectory $pd */
        $pd = $this->dice->create(
            ProjectDirectory::class,
            [__DIR__ . '/data/p1/other_dir', new MockGitMissing()]
        );
        self::assertEquals(
            $pd->getProjectRoot(),
            __DIR__ . '/data/p1',
            "traversal didn't recognize tests/data/p1 as a project directory"
        );
    }

    public function testLocateProjectViaGitRepository()
    {
        $toplevel = __DIR__ . '/data/p0';
        $git = new MockGitSimStatus($toplevel, []);
        /* @var ProjectDirectory $pd */
        $pd = $this->dice->create(
            ProjectDirectory::class,
            [$toplevel, $git]
        );
        self::assertEquals($pd->getProjectRoot(), $toplevel);
    }

    public function testVariantEnumeration()
    {
        /* @var ProjectDirectory $pd */
        $pd = $this->dice->create(
            ProjectDirectory::class,
            [__DIR__ . '/data/p2', new MockGitMissing()]
        );

        $variants = $pd->enumVariants();
        sort($variants['default']); // for consistent results
        self::assertEquals($variants['alpha'], ['postgres']);
        self::assertEquals($variants['beta'], ['postgres']);
        self::assertEquals($variants['default'], ['mysql', 'sqlite']);
    }

    /**
     * Get all vertices from a manifest in DFS order.
     *
     * @param Manifest $manifest
     * @return string[] vertice ids
     */
    public function getVertexIdsOfManifest(Manifest $manifest)
    {
        $vertexIds = [];
        $manifest->visitVerticesDFS(
            function (Vertex $vertex) use (&$vertexIds) {
                $vertexIds[] = $vertex->getId();
            }
        );
        return $vertexIds;
    }

    public function commonAssertionsForEmptyGitOnP3(ProjectDirectory $project)
    {
        $committedManifest =
            $project->loadCommittedManifest('postgres', 'default');
        $currentManifest =
            $project->loadCurrentManifest('postgres', 'default');
        self::assertNull($committedManifest);

        $vertexIds = $this->getVertexIdsOfManifest($currentManifest);
        $validationResults = $currentManifest->validate($project);

        // should have info for three steps in the manifest
        self::assertEquals(count($vertexIds), 3);
        self::assertEquals(count($validationResults), 3);

        /* @var ValidationResult $s */
        $s = $validationResults[$vertexIds[0]];
        self::assertEquals(
            $s->usedFileName,
            'postgres/default/schema/init.sql'
        );
        self::assertEquals(
            $s->status,
            ValidationResult::VR_STATUS_MATCHES_CURRENT
        );
        self::assertEquals($s->effectiveHash, $s->manifestHash);

        /* @var ValidationResult $s */
        $s = $validationResults[$vertexIds[1]];
        self::assertEquals(
            $s->usedFileName,
            'postgres/default/schema/update_users.sql'
        );
        self::assertEquals($s->status, ValidationResult::VR_STATUS_MISMATCH);
        self::assertNotEquals($s->effectiveHash, $s->manifestHash);

        /* @var ValidationResult $s */
        $s = $validationResults[$vertexIds[2]];
        self::assertEquals(
            $s->usedFileName,
            'postgres/default/schema/inexistent.sql'
        );
        self::assertEquals($s->status, ValidationResult::VR_STATUS_MISSING);
    }

    public function testManifestValidationWithoutGit()
    {
        /** @var ProjectDirectory $project */
        $project = $this->dice->create(
            ProjectDirectory::class,
            [__DIR__ . '/data/p3', new MockGitMissing()]
        );
        $this->commonAssertionsForEmptyGitOnP3($project);
    }

    public function testManifestValidationWithEmptyGit()
    {
        $toplevel = __DIR__ . '/data/p3';
        $git = new MockGitSimStatus($toplevel, []);

        /** @var ProjectDirectory $project */
        $project = $this->dice->create(
            ProjectDirectory::class,
            [$toplevel, $git]
        );
        $this->commonAssertionsForEmptyGitOnP3($project);
    }

    public function testManifestValidationWithMatchingGit()
    {
        $toplevel = __DIR__ . '/data/p3';

        $mockData = [];
        $mockGitFiles = [
            'postgres/default/manifest',
            'postgres/default/schema/init.sql',
            'postgres/default/schema/update_users.sql'
        ];
        foreach ($mockGitFiles as $filename) {
            $contents = file_get_contents($toplevel . '/' . $filename);
            $mockData[$filename] = [
                'filehash' => Manifest::calcGitBlobHash($contents),
                'contents' => $contents
            ];
        }
        $git = new MockGitSimStatus($toplevel, $mockData);

        /** @var ProjectDirectory $project */
        $project = $this->dice->create(
            ProjectDirectory::class,
            [$toplevel, $git]
        );

        $committedManifest =
            $project->loadCommittedManifest('postgres', 'default');
        self::assertNotNull($committedManifest);
        $currentManifest =
            $project->loadCurrentManifest('postgres', 'default');
        self::assertEquals(
            $committedManifest->getHash(),
            $currentManifest->getHash()
        );
    }

    public function testManifestValidationWithUpdatedManifest()
    {
        $toplevel = __DIR__ . '/data/p3';

        $mockData = [];
        $mockGitFiles = [
            'postgres/default/manifest',
            'postgres/default/schema/init.sql',
            'postgres/default/schema/update_users.sql'
        ];
        foreach ($mockGitFiles as $filename) {
            $contents = file_get_contents($toplevel . '/' . $filename);
            $mockData[$filename] = [
                'filehash' => Manifest::calcGitBlobHash($contents),
                'contents' => $contents
            ];
        }

        // simulate a local update to the manifest, compared to the latest
        // version in get.
        $old_manifest_txt = file_get_contents(
            __DIR__ . '/data/p3-historic-manifest'
        );
        $mockData['postgres/default/manifest'] = [
            'filehash' => Manifest::calcGitBlobHash($old_manifest_txt),
            'contents' => $old_manifest_txt
        ];
        $git = new MockGitSimStatus($toplevel, $mockData);

        /** @var ProjectDirectory $project */
        $project = $this->dice->create(
            ProjectDirectory::class,
            [$toplevel, $git]
        );

        $committedManifest =
            $project->loadCommittedManifest('postgres', 'default');
        self::assertNotNull($committedManifest);
        $currentManifest =
            $project->loadCurrentManifest('postgres', 'default');
        self::assertNotEquals(
            $committedManifest->getHash(),
            $currentManifest->getHash()
        );
    }

    public function testManifestValidationWithUpdatedStep()
    {
        $toplevel = __DIR__ . '/data/p3';

        $mockData = [];
        $mockGitFiles = [
            'postgres/default/manifest',
            'postgres/default/schema/init.sql',
            'postgres/default/schema/update_users.sql'
        ];
        foreach ($mockGitFiles as $filename) {
            $contents = file_get_contents($toplevel . '/' . $filename);
            $mockData[$filename] = [
                'filehash' => Manifest::calcGitBlobHash($contents),
                'contents' => $contents
            ];
        }

        // simulate a local update to the manifest, compared to the latest
        // version in get.
        $old_step_txt = file_get_contents(
            __DIR__ . '/data/p3-historic-init.sql'
        );
        $mockData['postgres/default/schema/init.sql'] = [
            'filehash' => Manifest::calcGitBlobHash($old_step_txt),
            'contents' => $old_step_txt
        ];
        $git = new MockGitSimStatus($toplevel, $mockData);

        /** @var ProjectDirectory $project */
        $project = $this->dice->create(
            ProjectDirectory::class,
            [$toplevel, $git]
        );

        $committedManifest =
            $project->loadCommittedManifest('postgres', 'default');
        self::assertNotNull($committedManifest);
        $currentManifest =
            $project->loadCurrentManifest('postgres', 'default');
        self::assertEquals(
            $committedManifest->getHash(),
            $currentManifest->getHash()
        );

        $vertexIds = $this->getVertexIdsOfManifest($currentManifest);
        self::assertEquals(count($vertexIds), 3);

        $validationResults = $currentManifest->validate($project);

        // should have info for three steps in the manifest
        self::assertEquals(count($validationResults), 3);

        /* @var ValidationResult $s */
        $s = $validationResults[$vertexIds[0]];
        self::assertEquals(
            $s->usedFileName,
            'postgres/default/schema/init.sql'
        );
        self::assertEquals(
            $s->status,
            ValidationResult::VR_STATUS_MATCHES_CURRENT
        );
        self::assertEquals($s->effectiveHash, $s->manifestHash);
    }
}
