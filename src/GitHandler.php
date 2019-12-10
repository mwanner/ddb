<?php

namespace Datahouse\Libraries\Database;

use Datahouse\Libraries\Database\Exceptions\UserError;

/**
 * Helper class for interacting with git.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class GitHandler implements GitInterface
{
    /* @var bool $gitExists */
    private $gitExists;

    public function __construct()
    {
        $this->gitExists = file_exists(Constants::GIT_CMD);
    }

    public function getToplevel()
    {
        if ($this->gitExists) {
            $cmd = Constants::GIT_CMD . " rev-parse --show-toplevel"
                . " 2> /dev/null";
            exec($cmd, $out, $rv);
            return $rv == 0 ? realpath($out[0]) : null;
        } else {
            return null;
        }
    }

    /**
     * @param string $blobCacheDir to store historic blobs to
     * @param string $hash         to retrieve
     * @return bool
     */
    public function populateBlobCache($blobCacheDir, $hash)
    {
        // FIXME: some of this method shouldn't be part of the GitInterface,
        // but is ProjectDirectory specific.
        if ($this->gitExists) {
            if (file_exists($blobCacheDir . '/' . $hash)) {
                return true;
            } else {
                if (is_writable($blobCacheDir)) {
                    $cmd = Constants::GIT_CMD . " cat-file blob " . $hash
                        . ' > ' . $blobCacheDir . '/' . $hash
                        . ' 2> /dev/null';
                    exec($cmd, $out, $rv);
                    assert(is_array($out));
                    if ($rv === 0) {
                        return true;
                    } else {
                        @unlink($blobCacheDir . '/' . $hash);
                        return false;
                    }
                } else {
                    throw new UserError(
                        "Using git, but cannot write to $blobCacheDir"
                    );
                }
            }
        } else {
            return false;
        }
    }

    /**
     * @param string $path to check
     * @return string|bool hash found in git or false
     */
    public function hashInGitHead($path)
    {
        if ($this->gitExists) {
            $cmd = Constants::GIT_CMD . ' rev-parse HEAD:' . $path
                . ' 2> /dev/null';
            exec($cmd, $out, $rv);
            assert(is_array($out));
            return $rv === 0 ? trim($out[0]) : false;
        } else {
            return false;
        }
    }
}
