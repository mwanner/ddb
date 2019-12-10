<?php

namespace Datahouse\Libraries\Database\Tests;

use Dice\Dice as Dice;

use Datahouse\Libraries\Database\ConnInfoLookup;
use Datahouse\Libraries\Database\Constants;

/**
 * Class ConnectionInfoTest
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class ConnInfoLookupTest extends \PHPUnit_Framework_TestCase
{
    /* @var Dice $dice */
    private $dice;

    public function setUp()
    {
        $this->dice = new Dice();

        $this->dice->addRule(
            'Datahouse\Libraries\JSON\Converter\Config',
            [
                'shared' => true,
                'constructParams' => [true, 512]
            ]
        );

        // An example project directory with a local database configuration
        // file.
        $dir = dirname(__FILE__) . '/data/p1';
        $this->dice->addRule(
            'Datahouse\Libraries\Database\ProjectDirectory',
            [
                'shared' => true,
                'constructParams' => [$dir],
                'substitutions' => [
                    'Datahouse\Libraries\Database\GitInterface' => new MockGitMissing()
                ]
            ]
        );

        $this->dice->addRule(
            'Datahouse\Libraries\Database\ConnInfoLookup',
            ['shared' => true]
        );
    }

    public function testLoadProjectSettings()
    {
        // Ensure tests are not dependent on the current user's settings.
        putenv('HOME=/nowhere/inexistent');

        $lookup = $this->dice->create(ConnInfoLookup::class);
        /* @var ConnInfoLookup $lookup */
        $knownIds = $lookup->getKnownConnectionIds();
        self::assertEquals([Constants::DEFAULT_DATABASE_ID], $knownIds);

        $def = $lookup->getConnInfoById(Constants::DEFAULT_DATABASE_ID);
        self::assertEquals('sqlite:test.db', $def->getUniqueServerDesc());
        self::assertEquals('sqlite:test.db', $def->getDSN());
    }
}
