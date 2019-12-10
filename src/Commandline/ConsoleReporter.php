<?php

namespace Datahouse\Libraries\Database\Commandline;

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

use Datahouse\Libraries\Database\IReporter;
use Datahouse\Libraries\Database\Logic\MigrationStep;

/**
 * An implementation of an IReporter for the console.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class ConsoleReporter implements IReporter
{
    private $output;
    private $maxDisplayFnLen;
    /* @var ProgressBar $progress */
    private $progress;

    /**
     * ConsoleReporter constructor.
     * @param OutputInterface $output    to write to
     * @param int             $termWidth to align
     */
    public function __construct(OutputInterface $output, $termWidth = 80)
    {
        $this->output = $output;
        $this->maxDisplayFnLen = $termWidth - 50;
    }

    /**
     * @param int $countSteps      to process
     * @param int $countStatements to process
     * @return void
     */
    public function reportStart($countSteps, $countStatements)
    {
        $this->output->writeln(
            "Applying " . $countSteps
            . " migration steps with " . $countStatements
            . " statements..."
        );

        $this->progress = new ProgressBar($this->output, $countStatements);
        $this->progress->setFormat('  [%bar%] %percent:3s%%  %message%');
        $this->progress->start();
    }

    /**
     * @param string $relFilename being processed
     * @return void
     */
    public function reportStep($relFilename)
    {
        if (strlen($relFilename) > $this->maxDisplayFnLen) {
            $displayFilename = '..' . substr(
                $relFilename,
                strlen($relFilename) - $this->maxDisplayFnLen - 2
            );
        } else {
            $displayFilename = $relFilename;
        }
        $this->progress->setMessage(' step ' . $displayFilename);
    }

    /**
     * @return void
     */
    public function advanceWithinStep()
    {
        $this->progress->advance();
    }

    /**
     * @return void
     */
    public function reportSuccess()
    {
        $this->progress->setMessage('done');
        $this->progress->finish();
        $this->output->write("\n\n");
    }

    /**
     * @param MigrationStep $step        that failed
     * @param string        $relFilename being processed
     * @param \Throwable    $exception   failure
     * @return void
     */
    public function reportFailure(
        MigrationStep $step,
        $relFilename,
        $exception
    ) {
        $this->progress->finish();
        $this->output->write("\n\n");

        $this->output->writeln(
            "<error>Failed applying a migration step</>"
        );
        $this->output->writeln(
            "file:     <fg=red;options=bold>" . $relFilename . "</>"
        );

        if ($exception->couldParseLineNumber()) {
            $line_no = $exception->getLineNo() + $exception->getLineOffset();
            $this->output->writeln(
                "line no:  <fg=red;options=bold>$line_no</>"
            );
        } else {
            $this->output->writeln(
                "line no:  <fg=red;options=bold>"
                . $exception->getLineNo()
                . "</> (start of failed statement"
            );
            $this->output->writeln(
                "<comment>Hint: Line numbers in the following "
                . "error message are relative to\n"
                . "the start of the statement.</comment>"
            );
        }
        $this->output->writeln("sql code: " . $exception->getCode() . "</>");

        $first_line = true;
        foreach (explode("\n", $exception->getMessage()) as $line) {
            $this->output->writeln(
                ($first_line ? "sql err:  " : "          ") . $line
            );
            $first_line = false;
        }

        $this->output->write("\n\n");
    }

    /**
     * @param string $msg to display
     * @return void
     */
    public function reportStatus($msg)
    {
        $this->output->writeln($msg);
    }

    /**
     * @param string $fullhash to shorten
     * @return string
     */
    public function getShortHash($fullhash)
    {
        return substr($fullhash, 0, 8);
    }
}
