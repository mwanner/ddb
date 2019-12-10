<?php

namespace Datahouse\Libraries\Database;

/**
 * Interface abstracting every git interaction. Allows writing unit tests that
 * mock this interface.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
interface GitInterface
{
    /**
     * Query git from the current working directory to figure out the
     * project root (or top level) of the current checkout.
     *
     * @return string|null absolute path or null if not successful
     */
    public function getToplevel();

    /**
     * Tries to retrieve a (historic) version of a file identified by its
     * git hash.
     *
     * @param  string $blobCacheDir blob cache directory to use
     * @param  string $hash         of the file to retrieve
     * @return bool whether or not the file could be retrieved
     */
    public function populateBlobCache($blobCacheDir, $hash);

    /**
     * Retrieve the (git) hash of the given file in the currently checked
     * out revision (HEAD).
     *
     * @param  string $path of the file to check
     * @return string|bool hash found in git or false
     */
    public function hashInGitHead($path);
}
