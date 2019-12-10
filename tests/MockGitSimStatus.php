<?php

namespace Datahouse\Libraries\Database\Tests;

use Datahouse\Libraries\Database\GitInterface;
use Datahouse\Libraries\Database\ProjectDirectory;

/**
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class MockGitSimStatus implements GitInterface
{
    private $toplevel;
    private $data;

    public function __construct($toplevel, $mockData)
    {
        $this->toplevel = $toplevel;
        $this->data = $mockData;
    }

    public function getToplevel()
    {
        return $this->toplevel;
    }

    /**
     * @param string $blobCacheDir to populate
     * @param string $hash         of the file to load from git
     * @return bool
     */
    public function populateBlobCache($blobCacheDir, $hash)
    {
        foreach ($this->data as $entry) {
            if ($entry['filehash'] == $hash) {
                file_put_contents(
                    $blobCacheDir . '/' . $entry['filehash'],
                    $entry['contents']
                );
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $path to check for existince in the last git commit
     * @return string|false hash of the version in git, or false
     */
    public function hashInGitHead($path)
    {
        if (substr($path, 0, 1) == '/') {
            $path = ProjectDirectory::relativePath($this->toplevel, $path);
        }
        if (array_key_exists($path, $this->data)) {
            return $this->data[$path]['filehash'];
        } else {
            return false;
        }
    }
}
