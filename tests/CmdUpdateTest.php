<?php

namespace Datahouse\Libraries\Database\Tests;

use Datahouse\Libraries\Database\Commandline\CreateCommand;
use Datahouse\Libraries\Database\Commandline\UpdateCommand;
use Datahouse\Libraries\Database\Manifest;

/**
 * Class CmdUpdateTest
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class CmdUpdateTest extends CommandTestBase
{
    protected function genCommand()
    {
        return new UpdateCommand($this->dice);
    }

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

    public function testNoOp()
    {
        $this->runOtherCommand(CreateCommand::class, []);

        $output = $this->tryCommand([
            'dbid' => 'default',
            'variant' => 'default'
        ], 0);

        self::assertRegExp('/Manifest is up to date/', $output);
    }

    public function prepareDisallowedModification()
    {
        $this->runOtherCommand(CreateCommand::class, []);

        // change schema/second, an immutable step that's not allowed to
        // be changed.
        file_put_contents(
            $this->toplevel . '/sqlite/default/schema/second.sql',
            "CREATE TABLE bar(id INT);\n",
            FILE_APPEND
        );
    }

    public function testDisallowedUpdate()
    {
        $this->prepareDisallowedModification();

        // Then try to update the manifest. This should fail.
        $output = $this->tryCommand([
            'dbid' => 'default',
            'variant' => 'default'
        ], 1);

        self::assertNotRegExp('/updated hash of/', $output);
        self::assertNotRegExp('/Manifest is up to date/', $output);
        self::assertRegExp('/Inconsistent manifest/', $output);
    }

    public function testForcedUpdate()
    {
        $this->prepareDisallowedModification();

        // Then try to update the manifest. This should fail.
        $output = $this->tryCommand([
            'dbid' => 'default',
            'variant' => 'default',
            '--force' => true
        ], 0);

        self::assertRegExp('/updated hash of/', $output);

        // Check the manifest. The old hash for second.sql should not
        // appear, anymore, but only the new one.
        $orig_hash = '/18f6ca99caf48f287f3a5c525957369a8da86154/';
        $new_hash = '/637d4e0d11269048b3e03985b70793da36e81299/';

        $manifest_path = $this->toplevel . '/sqlite/default/manifest';
        $manifest_data = file_get_contents($manifest_path);
        self::assertNotRegExp($orig_hash, $manifest_data);
        self::assertRegExp($new_hash, $manifest_data);
    }
}
