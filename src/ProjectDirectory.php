<?php

namespace Datahouse\Libraries\Database;

use Symfony\Component\Console\Output\OutputInterface;

use Datahouse\Libraries\Database\Exceptions\ParserError;
use Datahouse\Libraries\Database\Exceptions\UserError;

/**
 * Helper class for things related to the project tree, including queries to
 * the VCS.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2015-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class ProjectDirectory
{
    /* @var string projectRoot */
    private $projectRoot;

    /* @var GitInterface $git */
    private $git;

    /* @var bool useGit */
    private $useGit;

    /**
     * ProjectDirectory constructor.
     *
     * @param GitInterface $git interface
     * @param string|null  $dir starting directory for project root lookup
     */
    public function __construct(
        GitInterface $git,
        $dir = null
    ) {
        $this->git = $git;
        list($this->useGit, $this->projectRoot) =
            $this->getProjectRootInternal(isset($dir) ? $dir : getcwd());
    }

    /**
     * Whether or not this project directory is a git checkout.
     *
     * @return bool
     */
    public function useGit()
    {
        return $this->useGit;
    }

    /**
     * @param string $dir root directory to scan
     * @return array<string, array<string>>
     */
    private static function enumSupportedVariantsInternal($dir)
    {
        $variants = [];
        foreach (array_keys(Constants::$DATABASE_TYPES) as $type) {
            // For each kind of database supported, search for a so-called
            // directory containing manifest files, e.g.
            // mysql/vtinvest/manifest, mysql/vtpartnerportal/manifest.
            $db_dir = $dir . '/' . $type;
            if (is_dir($db_dir) && $dh = opendir($db_dir)) {
                while (($entry = readdir($dh)) !== false) {
                    // Some entries to exclude
                    if ($entry == '.'
                        || $entry == '..'
                        || $entry == Constants::COMMON_DIRECTORY
                    ) {
                        continue;
                    }
                    $variant_dir = $db_dir . '/' . $entry;
                    $path = $variant_dir . '/' . Constants::MANIFEST_FILENAME;
                    if (file_exists($path)) {
                        $variants[$entry][] = $type;
                    }
                }
                closedir($dh);
            }
        }
        return $variants;
    }

    /**
     * A couple heuristics to determine if we are at the root of a project,
     * judging by existing files, exclusively (useful for deployments, where
     * we don't have a git checkout, anymore).
     *
     * @param string $dir the directory to check
     * @return bool
     */
    public static function isProjectRoot($dir)
    {
        $variants = static::enumSupportedVariantsInternal($dir);
        return count($variants) > 0;
    }

    /**
     * @param string $startDir where to start scanning from
     * @return array
     */
    private function getProjectRootInternal($startDir)
    {
        // Scan for a .db.conf.json file along-side a postgres, mysql or
        // sqlite directory. Starting from the current directory working
        // all the way up to the root, if necessary.
        for ($dir = $startDir; strlen($dir) > 1; $dir = dirname($dir)) {
            if (static::isProjectRoot($dir)) {
                break;
            }
        }

        // Set dir to null if we reached the filesystem's root.
        if (strlen($dir) <= 1) {
            $dir = null;
        }

        // Try git as well.
        $git_root = $this->git->getToplevel();

        if (isset($dir) && isset($git_root)) {
            // if both are found, prefer the traversal variant
            return [true, $dir];
        } elseif (isset($dir) && is_null($git_root)) {
            return [false, $dir];
        } elseif (is_null($dir) && isset($git_root)) {
            return [true, $git_root];
        } else {
            throw new UserError("Cannot determine project root");
        }
    }

    /**
     * Fetch an old variant of a file from git by its blob hash and stores it
     * in the local project directory's BLOB_DIR.
     *
     * @param string $variantDir of the database to cache blobs for
     * @param string $hash       of the file to retrieve
     * @return bool whether or not the operation succeeded
     */
    public function fetchGitObject($variantDir, $hash)
    {
        if ($this->useGit) {
            $blobCacheDir = $variantDir . '/' . Constants::BLOB_DIR;
            if (!is_dir($blobCacheDir)) {
                if (!mkdir($blobCacheDir, 0777, true)) {
                    throw new \RuntimeException(
                        "Cannot create blob cache directory: "
                        . $blobCacheDir
                    );
                }
            }
            return $this->git->populateBlobCache($blobCacheDir, $hash);
        }
        return false;
    }

    /**
     * @param string $variantDir to search (using the new-style variant)
     * @param string $hash       to lookup
     * @return string|null absolute path to the historic blob; or null
     */
    public function getHistoricBlobPath($variantDir, $hash)
    {
        $oldPath = $this->getProjectRoot()
            . '/' . Constants::BLOB_DIR . '/' . $hash;
        $newPath = $variantDir
            . '/' . Constants::BLOB_DIR . '/' . $hash;
        if (file_exists($newPath)) {
            return $newPath;
        } elseif (file_exists($oldPath)) {
            return $oldPath;
        } else {
            return null;
        }
    }

    /**
     * Returns true if the file exists in git's HEAD revision.
     *
     * @param string $path to check
     * @return string|bool hash found in git or false
     */
    public function hashInGitHead($path)
    {
        // Determine a $relPath that eliminates all symlinks.
        $absPath = realpath($this->getProjectRoot() . '/' . $path);
        $relPath = $this->getRelativePath($absPath);
        return $this->useGit ? $this->git->hashInGitHead($relPath) : false;
    }

    /**
     * Starting from the current working directory, this scans for a project
     * root, going upwards in the file system tree for a project root.
     *
     * @return string absolute path of the project root
     */
    public function getProjectRoot()
    {
        assert(strlen($this->projectRoot) > 0);
        return $this->projectRoot;
    }

    /**
     * @param string $type    of the database to work on
     * @param string $variant of the manifest to load
     * @return string
     */
    public function getVariantDir($type, $variant)
    {
        return $this->getProjectRoot() . '/' . $type . '/' . $variant;
    }

    /**
     * Calculate a relative path - from the PHP documentation at
     * http://php.net/manual/en/function.realpath.php
     *
     * @param string $from initial path to convert from
     * @param string $to   new path to base to
     * @param string $ps   path separator
     * @return string
     */
    public static function relativePath($from, $to, $ps = DIRECTORY_SEPARATOR)
    {
        $arFrom = explode($ps, rtrim($from, $ps));
        $arTo = explode($ps, rtrim($to, $ps));
        while (count($arFrom) && count($arTo) && ($arFrom[0] == $arTo[0])) {
            array_shift($arFrom);
            array_shift($arTo);
        }
        return str_pad("", count($arFrom) * 3, '..' . $ps)
            . implode($ps, $arTo);
    }

    /**
     * Returns the project root directory relative to the current working
     * directory.
     *
     * @return string relative path
     */
    public function getRelativeProjectRoot()
    {
        assert(strlen($this->projectRoot) > 0);
        return static::relativePath(getcwd(), $this->projectRoot);
    }

    /**
     * Returns a path relative to the project root.
     *
     * @param string $path to convert
     * @return string relative path
     */
    public function getRelativePath($path)
    {
        assert(strlen($this->projectRoot) > 0);
        return static::relativePath($this->projectRoot, $path);
    }

    /**
     * Enumerate all variants for databases known to this project.
     *
     * @return array list of variant names, consisting of a database type and
     *               variant name.
     */
    public function enumVariants()
    {
        return static::enumSupportedVariantsInternal($this->getProjectRoot());
    }

    /**
     * Loads the last committed manifest (if any) and the current manifest
     * for the given type and variant.
     *
     * @param string $type    of the database to work on
     * @param string $variant of the manifest to load
     * @return array of two Manifests
     * @deprecated Use loadCommittedManifest or loadCurrentManifest
     */
    public function loadManifests($type, $variant)
    {
        $variantDir = $this->getVariantDir($type, $variant);
        $manifest = Manifest::loadFromFile(
            $variantDir . '/' . Constants::MANIFEST_FILENAME,
            $variantDir
        );
        $relFn = $type . '/' . $variant . '/' . Constants::MANIFEST_FILENAME;
        $gitHash = $this->hashInGitHead($relFn);
        if ($gitHash === false) {
            // A new manifest, not ever committed to git, yet. Or not in a
            // git tree.
            return [null, $manifest];
        } elseif ($gitHash != $manifest->getHash()) {
            $lastManifestFilename = $this->getVariantDir($type, $variant)
                . '/' . Constants::BLOB_DIR . '/' . $gitHash;
            if (file_exists($lastManifestFilename)
                || $this->fetchGitObject($variantDir, $gitHash)
            ) {
                $lastManifest = Manifest::loadFromFile(
                    $lastManifestFilename,
                    $variantDir
                );
                return [$lastManifest, $manifest];
            } else {
                throw new \RuntimeException("Cannot load the old manifest");
            }
        } else {
            return [$manifest, $manifest];
        }
    }

    /**
     * Loads the current manifest for the given type and variant.
     *
     * @param string $type    of the database to work on
     * @param string $variant of the manifest to load
     * @return Manifest
     */
    public function loadCurrentManifest($type, $variant)
    {
        $variantDir = $this->getVariantDir($type, $variant);
        $manifest = Manifest::loadFromFile(
            $variantDir . '/' . Constants::MANIFEST_FILENAME,
            $variantDir
        );
        return $manifest;
    }

    /**
     * Loads the last committed manifest (if any) for the given type and
     * variant.
     *
     * @param string $type    of the database to work on
     * @param string $variant of the manifest to load
     * @return Manifest|null
     */
    public function loadCommittedManifest($type, $variant)
    {
        $variantDir = $this->getVariantDir($type, $variant);
        $relPath = $type . '/' . $variant . '/' . Constants::MANIFEST_FILENAME;
        $gitHash = $this->hashInGitHead($relPath);
        if ($gitHash) {
            $lastManifestFilename = $this->getVariantDir($type, $variant)
                . '/' . Constants::BLOB_DIR . '/' . $gitHash;
            if (file_exists($lastManifestFilename)
                || $this->fetchGitObject($variantDir, $gitHash)
            ) {
                return Manifest::loadFromFile(
                    $lastManifestFilename,
                    $variantDir
                );
            } else {
                throw new \RuntimeException("Cannot load the old manifest");
            }
        } else {
            // No manifest committed, yet. Or not in a git tree.
            return null;
        }
    }

    /**
     * Update a manifest by replacing existing hashes or appending migration
     * steps at the end of the file.
     *
     * @param OutputInterface $output  to report actions performed
     * @param string          $type    of the database
     * @param string          $variant of the schema
     * @param array           $updated definition of steps to add or modify
     * @return int exit code
     */
    public function updateManifest(
        OutputInterface $output,
        $type,
        $variant,
        array $updated
    ) {
        $variantDir = $this->getVariantDir($type, $variant);
        $manifestFilename = $variantDir . '/' . Constants::MANIFEST_FILENAME;
        $manifestData = file_get_contents($manifestFilename);
        if ($manifestData === false) {
            throw new ParserError(
                $manifestFilename,
                1,
                "Unable to read manifest."
            );
        }

        foreach ($updated as $projectRelFileName => $def) {
            if ($def['optype'] === 'add') {
                $manifestRelPath = static::relativePath(
                    $variantDir,
                    $this->getProjectRoot() . '/' . $projectRelFileName
                );

                $manifestData .= "  -\n"
                    . "    hash: " . $def['newHash'] . "\n"
                    . "    path: " . $manifestRelPath . "\n";
                if (array_key_exists('mutable', $def) && $def['mutable']) {
                    $manifestData .= "    mutable: true\n";
                }
            } elseif ($def['optype'] === 'update') {
                // results from an update --force invocation
                $manifestData = str_replace(
                    $def['oldHash'],
                    $def['newHash'],
                    $manifestData
                );
            } else {
                throw new \LogicException('unknown optype for updateManifest');
            }
        }

        file_put_contents($manifestFilename, $manifestData);

        // Report actions performed.
        $output->writeln(
            "Updated manifest of schema variant <fg=yellow>$variant</>:"
        );

        $emit_verbose_info = (
            $output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE
        );
        foreach ($updated as $projectRelFileName => $def) {
            $manifestRelPath = static::relativePath(
                $variantDir,
                $this->getProjectRoot() . '/' . $projectRelFileName
            );
            if ($def['optype'] == 'add') {
                $output->writeln("  added new step : " . $manifestRelPath);
                if ($emit_verbose_info) {
                    $output->writeln("             hash: " . $def['newHash']);
                }
            } else {
                $output->writeln("  updated hash of: " . $manifestRelPath);
                if ($emit_verbose_info) {
                    $output->writeln("             from: " . $def['oldHash']);
                    $output->writeln("               to: " . $def['newHash']);
                }
            }
        }

        return 0;
    }
}
