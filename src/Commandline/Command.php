<?php

namespace Datahouse\Libraries\Database\Commandline;

use Dice\Dice;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

use Datahouse\Libraries\Database\ConnectionInfo\BaseConnectionInfo;
use Datahouse\Libraries\Database\ConnInfoLookup;
use Datahouse\Libraries\Database\Constants;
use Datahouse\Libraries\Database\DbFactory;
use Datahouse\Libraries\Database\Exceptions\UserError;
use Datahouse\Libraries\Database\IReporter;
use Datahouse\Libraries\Database\ProjectDirectory;

/**
 * An abstract base Command class that provides methods common to all ddb
 * commands.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
abstract class Command extends \Symfony\Component\Console\Command\Command
{
    /** @var Dice $dice */
    private $dice;

    /** @var ProjectDirectory $project */
    protected $project;

    /** @var ConnInfoLookup $lookup */
    protected $lookup;

    /** @var IReporter $reporter */
    protected $reporter;

    /**
     * Create the command object, initialize the command name and its
     * description from static consts. Configure the DI container.
     *
     * @param Dice|null $dice DI container to use
     */
    public function __construct(Dice $dice = null)
    {
        parent::__construct(static::NAME);
        $this->setDescription(static::DESC);

        if (isset($dice)) {
            $this->dice = $dice;
        } else {
            $this->dice = new Dice;
            DbFactory::addDiceRules($this->dice);
        }

        $this->reporter = null;
    }

    /**
     * Add a standard dbid argument - a helper for derived classes.
     *
     * @return void
     */
    protected function addDatabaseConfig()
    {
        $this->addArgument(
            'dbid',
            InputArgument::OPTIONAL,
            'optional database id'
        );
    }

    /**
     * Add a standard variant argument - a helper for derived classes.
     *
     * @return void
     */
    protected function addVariantConfig()
    {
        $this->addArgument(
            'variant',
            InputArgument::OPTIONAL,
            'schema variant to use'
        );
    }

    /**
     * @param InputInterface $input symfony console input
     * @return array as both, the dbid and variant are optional, the variant
     *         may actually be stored in the dbid argument. Therefore,
     *         this method returns an array [ConnectionInfo, variantName].
     * @throws UserError
     */
    protected function interpretArguments(InputInterface $input)
    {
        $rootDir = $this->project->getProjectRoot();
        if (is_null($rootDir)) {
            throw new UserError("Not within a known project directory.");
        }

        // Args are both optional and interchangeable, but Symfony Console
        // doesn't quite support that.
        $args = [];
        if (!is_null($input->getArgument('dbid'))) {
            $args[] = $input->getArgument('dbid');
        }
        if (!is_null($input->getArgument('variant'))) {
            $args[] = $input->getArgument('variant');
        }

        $availableVariants = $this->project->enumVariants();
        $knownIds = $this->lookup->getKnownConnectionIds();

        $dbid = null;
        $dbtype = null;
        $variant = null;
        foreach ($args as $arg) {
            if (in_array($arg, $knownIds) && is_null($dbid)) {
                $dbid = $arg;
            } elseif (array_key_exists($arg, $availableVariants)
                && is_null($variant)
            ) {
                $variant = $arg;
            } else {
                throw new UserError(
                    "Given argument '" . $arg . "' is neither a variant"
                    . " of this project nor a known database."
                );
            }
        }

        if (is_null($variant)) {
            if (array_key_exists(
                Constants::DEFAULT_VARIANT,
                $availableVariants
            )) {
                $variant = Constants::DEFAULT_VARIANT;
            } else {
                throw new UserError(
                    "No default variant found, please specifiy a schema variant."
                );
            }
        } elseif (!array_key_exists($variant, $availableVariants)) {
            throw new UserError(
                "Given variant '$variant' does not exist for this project."
            );
        }

        if ($dbid) {
            $dbtype = $this->lookup->getConnInfoById($dbid)->getType();
        } else {
            $typesForVariant = $availableVariants[$variant];
            if (count($typesForVariant) == 1) {
                $dbid = Constants::DEFAULT_DATABASE_ID;
                $dbtype = $typesForVariant[0];
            } else {
                throw new UserError(
                    "Cannot automatically determine database type "
                    . "for variant $variant."
                );
            }
        }

        return [$dbid, $variant, $dbtype];
    }

    /**
     * @param string $dbid    to lookup
     * @param string $variant to use
     * @return BaseConnectionInfo
     * @throws UserError
     */
    protected function loadDatabaseConfig($dbid, $variant)
    {
        $availableVariants = $this->project->enumVariants();
        $knownIds = $this->lookup->getKnownConnectionIds();
        $connInfo = $this->lookup->getConnInfoById($dbid);

        if (!$connInfo &&
            !in_array(Constants::DEFAULT_DATABASE_ID, $knownIds)) {
            throw new UserError(
                "No default database found, please specify a database id."
            );
        }

        $type = $connInfo->getType();
        if (!in_array($type, $availableVariants[$variant])) {
            throw new UserError(
                "Mismatch between database type and schema variant",
                "Database has type '$type' while the variant '$variant'"
                . " is only defined for:\n"
                . implode(', ', $availableVariants[$variant])
            );
        }

        return $connInfo;
    }

    /**
     * An execute command covered by common exception handling for all
     * UserErrors.
     *
     * @param InputInterface  $input  console input
     * @param OutputInterface $output console output
     * @return int exit code
     */
    abstract protected function coveredExecute(
        InputInterface $input,
        OutputInterface $output
    );

    /**
     * {@inheritdoc}
     *
     * @param InputInterface  $input  console input
     * @param OutputInterface $output console output
     * @return int exit code
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $term = new Terminal();
        $this->reporter = new ConsoleReporter($output, $term->getWidth());

        try {
            // Initialize the project and lookup objects here, rather than in
            // the constructor (via DI) so we can handle exceptions.
            $this->project = $this->dice->create(
                'Datahouse\\Libraries\\Database\\ProjectDirectory'
            );
            $this->lookup = $this->dice->create(
                'Datahouse\\Libraries\\Database\\ConnInfoLookup'
            );
            return $this->coveredExecute($input, $output);
        } catch (UserError $e) {
            $output->writeln('<error>' . $e->getMessage() . '</>');
            $hint = $e->getHint();
            if (strlen($hint) > 0) {
                $output->writeln('');
                $output->writeln('<comment>' . $hint . '</comment>');
            }
            $output->writeln('');
            return 1;
        }
    }
}
