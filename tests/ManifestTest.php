<?php

namespace Datahouse\Libraries\Database\Tests;

use Fhaculty\Graph\Vertex;

use Datahouse\Libraries\Database\Exceptions\UserError;
use Datahouse\Libraries\Database\Logic\AppliedStep;
use Datahouse\Libraries\Database\Manifest;
use Datahouse\Libraries\Database\ManifestComparisonResult;

/**
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class ManifestTest extends \PHPUnit_Framework_TestCase
{
    public static function loadTestManifest($filename)
    {
        $path = __DIR__ . '/data/manifests/' . $filename;
        return Manifest::loadFromFile($path, __DIR__ . '/data/manifests');
    }

    public function testLoadManifestWithoutVersion()
    {
        $this->setExpectedException(UserError::class);
        static::loadTestManifest('no_version.manifest');
    }

    public function testLoadInvalidManifest()
    {
        $this->setExpectedException(UserError::class);
        static::loadTestManifest('invalid.manifest');
    }

    public function testLoadManifestNotAMapping()
    {
        $this->setExpectedException(UserError::class);
        static::loadTestManifest('list.manifest');
    }

    public function testLoadManifestWithUnknownVersion()
    {
        $this->setExpectedException(UserError::class);
        static::loadTestManifest('future_version.manifest');
    }

    public function testLoadManifest()
    {
        $manifest = static::loadTestManifest('good.manifest');
        self::assertEquals($manifest->getTotalVertexCount(), 1);

        $vertexIds = [];
        $manifest->visitVerticesDFS(
            function (Vertex $vertex) use (&$vertexIds) {
                $vertexIds[] = $vertex->getId();
            }
        );

        $step = $manifest->getStep($vertexIds[0]);
        self::assertTrue($manifest->hasStepByHash($step->filehash));
    }

    public function testManifestDiffUpgradable()
    {
        $manifest = static::loadTestManifest('good.manifest');
        $result = $manifest->compareWith([]);

        self::assertEquals(
            ManifestComparisonResult::MIGRATABLE,
            $result->status
        );

        self::assertEquals(1, count($result->paths));
        self::assertEquals(1, count($result->paths[0]));

        $vertexId = $result->paths[0][0];
        self::assertEquals(
            'ef5ea2dae26134542b8e8af29044eee66e47ac63',
            $manifest->getStep($vertexId)->filehash
        );
    }

    public function testManifestDiffSatisfying()
    {
        $manifest = static::loadTestManifest('good.manifest');

        $appliedSteps = [
            new AppliedStep(
                '2016-03-07T14:17:35 +01:00',
                'ef5ea2dae26134542b8e8af29044eee66e47ac63',
                'schema/example.sql'
            )
        ];
        $result = $manifest->compareWith($appliedSteps);

        self::assertEquals(
            ManifestComparisonResult::SATISFIES_TARGETS,
            $result->status
        );
        self::assertEquals(0, count($result->paths));
    }

    public function testManifestDiffUnableToUpgarde()
    {
        $manifest = static::loadTestManifest('good.manifest');

        $appliedSteps = [
            new AppliedStep(
                '2016-03-07T14:17:35 +01:00',
                'd1ffe1ea10000000000000000000000000000000',
                'schema/mismatchingStep.sql'
            )
        ];
        $result = $manifest->compareWith($appliedSteps);

        self::assertEquals(
            ManifestComparisonResult::UNABLE_TO_MIGRATE,
            $result->status
        );
        self::assertEquals(0, count($result->paths));
    }

    public function testStmtMutable1()
    {
        self::assertTrue(
            Manifest::statementMutable('CREATE FUNCTION xy();')
        );
    }

    public function testStmtMutable2()
    {
        // some unnecessary spaces, used to confuse the method
        self::assertTrue(
            Manifest::statementMutable('CREATE   OR    REPLACE FUNCTION xy();')
        );
    }

    public function testStmtMutable3()
    {
        $sql = 'CREATE DEFINER=`user`@`localhost`
          FUNCTION `my_func`(...) RETURNS ...';
        self::assertTrue(Manifest::statementMutable($sql));
    }
}
