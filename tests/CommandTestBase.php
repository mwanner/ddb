<?php

namespace Datahouse\Libraries\Database\Tests;

use Dice\Dice;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Filesystem\Filesystem;

use Datahouse\Libraries\Database\Commandline\Command;
use Datahouse\Libraries\Database\Commandline\DDBApplication;

/**
 * Class CommandTestBase
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
abstract class CommandTestBase extends \PHPUnit_Framework_TestCase
{
    /** @var DDBApplication $app */
    private $app;

    /** @var Command $cmd the command to test */
    private $cmd;

    /* @var string temporary test directory to work in */
    protected $toplevel;

    /* @var Dice $dice DI Container instance */
    protected $dice;

    /* @var array $git */
    protected $git;

    public function setUp()
    {
        $this->setUpProjectDirectory();
        $this->setUpDice();

        chdir($this->toplevel);

        $this->app = new DDBApplication();
        $this->cmd = $this->genCommand();
        $this->cmd->setApplication($this->app);
    }

    /**
     * Derived classes can override this method to define a non-empty initial
     * git status (i.e. simulate commits and known files). The default
     * implementation emulates a new repo with nothing committed, yet.
     *
     * @param string $srcdir absolute path to the variant directory
     * @return array to be passed to MockGitSimStatus
     */
    protected function getMockGitStatus($srcdir)
    {
        return [];
    }

    /**
     * @return string default source directory for most tests relying on
     *                 this base class.
     */
    protected function getTestDataDirectory()
    {
        return __DIR__ . '/data/p4';
    }

    protected function setUpProjectDirectory()
    {
        $srcdir = $this->getTestDataDirectory();
        $this->toplevel = __DIR__ . '/data/p4.work';
        (new Filesystem)->mirror($srcdir, $this->toplevel);

        $mockData = $this->getMockGitStatus($srcdir);
        $this->git = new MockGitSimStatus($this->toplevel, $mockData);
    }

    protected function setUpDice()
    {
        $this->dice = new Dice();

        // Add a global JSON parser config
        $this->dice->addRule(
            'Datahouse\\Libraries\\JSON\\Converter\\Config',
            [
                'shared' => true,
                'constructParams' => [true, 512],
            ]
        );

        $this->dice->addRule(
            'Datahouse\\Libraries\\Database\\ConnInfoLookup',
            ['shared' => true]
        );

        $this->dice->addRule(
            'Datahouse\\Libraries\\Database\\ProjectDirectory',
            [
                'shared' => true,
                'constructParams' => [null, $this->git, $this->toplevel],
                'substitutions' => [
                    'Datahouse\\Libraries\\Database\\GitInterface' => $this->git
                ],
            ]
        );
    }

    public function tearDown()
    {
        (new Filesystem)->remove($this->toplevel);
    }

    /**
     * @return Command object instantiated to test
     */
    abstract protected function genCommand();

    /**
     * @param array $input       test inputs
     * @param int   $expExitCode expected exit code
     * @return string all output of the tested command
     * @throws \Exception
     */
    public function tryCommand(array $input = [], $expExitCode = 0)
    {
        return $this->runAnyCommand($this->cmd, $input, $expExitCode);
    }

    /**
     * Runs any other command as a helper in setting up the test.
     *
     * @param string $className   command to run
     * @param array  $input       inputs for the command
     * @param int    $expExitCode expected exit code of the command
     * @return string all output of the command just run
     * @throws \Exception
     */
    public function runOtherCommand(
        $className,
        array $input = [],
        $expExitCode = 0
    ) {
        $cmd = new $className();
        /* @var Command $cmd */
        $cmd->setApplication($this->app);
        return $this->runAnyCommand($cmd, $input, $expExitCode);
    }

    /**
     * @param Command $cmd         command (instance) to run
     * @param array   $input       input arguments
     * @param int     $expExitCode expected exit code
     * @return string all output of the command
     * @throws \Exception
     */
    private function runAnyCommand(
        Command $cmd,
        array $input = [],
        $expExitCode = 0
    ) {
        $input = new ArrayInput($input);
        $output = new BufferedOutput();
        /* @var Command $cmd */
        $exitCode = $cmd->run($input, $output);
        $output_str = $output->fetch();
        self::assertEquals($exitCode, $expExitCode,
                           "exit code mismatch\n" .
                           "===== BEGIN OUTPUT =====\n" .
                           $output_str .
                           "===== END OUTPUT =====\n");
        return $output_str;
    }
}
