<?php

namespace Datahouse\Libraries\Database\Commandline;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use Datahouse\Libraries\Database\Constants;

/**
 * Command to list known databases and schema variants.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2018-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class ListCommand extends Command
{
    const NAME = 'list';
    const DESC = 'Lists known databases and schema variants';

    /**
     * @return void
     */
    protected function configure()
    {
        // no options or arguments to add
    }

    /**
     * {@inheritdoc}
     *
     * @param InputInterface  $input  console input
     * @param OutputInterface $output console output
     * @return int exit code
     */
    protected function coveredExecute(
        InputInterface $input,
        OutputInterface $output
    ) {
        $conn_ids = $this->lookup->getKnownConnectionIds();

        if (count($conn_ids) > 0) {
            $output->writeln("Defined database ids:");
            sort($conn_ids);

            $line = '   ';
            while (count($conn_ids) > 0) {
                $next = array_shift($conn_ids);
                if (strlen($line) + strlen($next) > 76) {
                    $output->writeln($line);
                    $line = '   ';
                }
                if ($next == Constants::DEFAULT_DATABASE_ID) {
                    $line .= '<fg=green;options=bold>default</>';
                } else {
                    $line .= $next;
                }
                if (count($conn_ids) > 0) {
                    $line .= ', ';
                }
            }
            $output->writeln('');
        }

        if ($this->project->isProjectRoot(getcwd())) {
            $variants = $this->project->enumVariants();
            $perDbType = [];
            foreach ($variants as $variant => $dbtypes) {
                foreach ($dbtypes as $dbtype) {
                    $perDbType[$dbtype][] = $variant;
                }
            }

            if (count($variants) > 0) {
                $output->writeln("Known schema variants of this project:");
                if (count($perDbType) == 1) {
                    $variants_str = str_replace(
                        Constants::DEFAULT_VARIANT,
                        '<fg=green;options=bold>'
                            . Constants::DEFAULT_VARIANT . '</>',
                        implode(', ', array_keys($variants))
                    );
                    $output->writeln('   ' . $variants_str);
                } else {
                    foreach ($variants as $variant => $dbtypes) {
                        if ($variant == Constants::DEFAULT_VARIANT) {
                            $variant = '<fg=green;options=bold>' . $variant . '</>';
                        }
                        $output->writeln('   ' . $variant
                            . ' (' . implode(', ', $dbtypes) . ')');
                    }
                }
            }
            $output->writeln('');
        } else {
            $output->writeln('<comment>Not within a datahouse project'
                . ' (of known structure).</>');
            $output->writeln('');
        }
    }
}
