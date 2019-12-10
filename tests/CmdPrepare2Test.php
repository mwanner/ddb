<?php

namespace Datahouse\Libraries\Database\Tests;

use Datahouse\Libraries\Database\Commandline\PrepareCommand;
use Datahouse\Libraries\Database\Commandline\UpdateCommand;
use Datahouse\Libraries\Database\Constants;
use Datahouse\Libraries\Database\Manifest;

/**
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class CmdPrepare2Test extends CommandTestBase
{
    /**
     * Generate the command to be tested.
     *
     * @return PrepareCommand
     */
    protected function genCommand()
    {
        return new PrepareCommand($this->dice);
    }

    /**
     * @return string non-default source directory for this test
     */
    protected function getTestDataDirectory()
    {
        return __DIR__ . '/data/p5';
    }

    /**
     * @param string $srcdir absolute path to the variant directory
     * @return array to be passed to MockGitSimStatus
     */
    protected function getMockGitStatus($srcdir)
    {
        $mockData = [];
        $mockGitFiles = [
            'sqlite/default/manifest',
            'sqlite/default/schema/init.sql',
            'sqlite/default/schema/second.sql',
            'sqlite/default/views/third.sql',
            // Add the old version of this file as well, so the mock routines
            // have a chance to come up with the historic content.
            'sqlite/default/views/third.bak.sql'
        ];
        foreach ($mockGitFiles as $filename) {
            $contents = file_get_contents($srcdir . '/' . $filename);
            $mockFilename = str_replace('.bak', '', $filename);
            $mockData[$mockFilename] = [
                'filehash' => Manifest::calcGitBlobHash($contents),
                'contents' => $contents
            ];
        }
        return $mockData;
    }

    /**
     * Add something to p5, so there's an old revision of the
     *
     * @return void
     * @throws \Exception
     */
    public function testPrepareModifiedManifest()
    {
        $blobDir = $this->toplevel . '/sqlite/default/' . Constants::BLOB_DIR;
        self::assertFalse(is_dir($blobDir));

        $output = $this->tryCommand([], 0);
        self::assertRegExp('/Preparing sqlite\\/default/', $output);

        // Expect a warning about the manifest being modified locally.
        self::assertNotRegExp(
            '/Manifest for \S+ is modified locally/',
            $output
        );

        // The prepare command must store a copy of a historic variant of a
        // migration step, not complain about changed files.
        self::assertNotRegExp('/mutable file has changed/', $output);
        self::assertNotRegExp('/updated mutable step/', $output);

        // Expect the historic variant to appear in the .ddb directory.
        self::assertTrue(is_dir($blobDir));
        $histPath = $blobDir . '/66d08bd4e231e0c2d414ce8c1b5fd766566fe79b';
        self::assertTrue(is_file($histPath));
    }
}
