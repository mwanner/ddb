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
class CmdPrepareTest extends CommandTestBase
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
            'sqlite/default/views/third.sql'
        ];
        foreach ($mockGitFiles as $filename) {
            $contents = file_get_contents($srcdir . '/' . $filename);
            $mockData[$filename] = [
                'filehash' => Manifest::calcGitBlobHash($contents),
                'contents' => $contents
            ];
        }
        return $mockData;
    }

    /**
     * Test the command against a missing database.
     *
     * @return void
     * @throws \Exception
     */
    public function testMissingDatabase()
    {
        $output = $this->tryCommand([], 0);

        self::assertNotRegExp('/database default does not exist/', $output);
    }

    /**
     * Test with a manifest that does not need any old revisions of mutable
     * migration steps.
     *
     * @return void
     * @throws \Exception
     */
    public function testNothingToPrepare()
    {
        $ddbDir = $this->toplevel . '/sqlite/default/.ddb';
        self::assertFalse(is_dir($ddbDir));

        $output = $this->tryCommand([]);

        self::assertRegExp('/Preparing sqlite\\/default/', $output);
        self::assertTrue(is_dir($ddbDir));
    }

    /**
     * Modify a mutable step, so the manifest has local and uncommitted
     * modifications.
     *
     * @return void
     * @throws \Exception
     */
    public function testPrepareModifiedManifest()
    {
        $blobDir = $this->toplevel . '/sqlite/default/' . Constants::BLOB_DIR;
        self::assertFalse(is_dir($blobDir));

        // change views/third, a mutable step
        file_put_contents(
            $this->toplevel . '/sqlite/default/views/third.sql',
            "-- just some comment\n",
            FILE_APPEND
        );

        $output = $this->runOtherCommand(UpdateCommand::class, [
            'dbid' => 'default',
            'variant' => 'default'
        ]);
        self::assertRegExp('/added new step/', $output);

        // ddb update directly adds the historic version of the step to the
        // blob directory.
        self::assertTrue(is_dir($blobDir),
                         "blob dir in '$blobDir' has been created");
        $histPath = $blobDir . '/66d08bd4e231e0c2d414ce8c1b5fd766566fe79b';
        self::assertTrue(is_file($histPath),
                         "old variant of third.sql has been created");

        $manifestPath = $this->toplevel . '/sqlite/default/manifest';
        $manifestData = file_get_contents($manifestPath);
        $oldHash = "/66d08bd4e231e0c2d414ce8c1b5fd766566fe79b/";
        self::assertRegExp($oldHash, $manifestData);


        // Let's check if ddb re-validates stuff in the blob dir.
        $output2 = $this->tryCommand([]);
        self::assertRegExp('/Preparing sqlite\\/default/', $output2);

        // Expect a warning about the manifest being modified locally.
        self::assertRegExp(
            '/Manifest for \S+ is modified locally/',
            $output2
        );

        // The prepare command must store a copy of a historic variant of a
        // migration step, not complain about changed files.
        self::assertNotRegExp('/mutable file has changed/', $output2);
        self::assertNotRegExp('/updated mutable step/', $output2);
        self::assertTrue(is_dir($blobDir));
        self::assertTrue(is_file($histPath));
    }
}
