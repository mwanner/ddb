<?php

namespace Datahouse\Libraries\Database\Commandline;

use Symfony\Component\Console\Command\Command as SymfonyCommand;

use Symfony\Component\Console\Helper\DescriptorHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The most important and all famous help command.
 *
 * @author Markus Wanner <markus@bluegap.ch>
 * @copyright (c) 2016-2019 Datahouse AG, https://www.datahouse.ch
 * @license MIT
 */
class HelpCommand extends SymfonyCommand
{
    private $command;

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    protected function configure()
    {
        $this->ignoreValidationErrors();
        $this->setName('help')
            ->setDefinition([new InputArgument(
                'command_name',
                InputArgument::OPTIONAL,
                'The command name',
                'help'
            )])
            ->setDescription('Displays help for a command')
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> command displays help for a given command:

  <info>php %command.full_name% migrate</info>
EOF
            );
    }

    /**
     * Sets the command.
     *
     * @param Command $command The command to set
     * @return void
     */
    public function setCommand(SymfonyCommand $command)
    {
        $this->command = $command;
    }

    /**
     * {@inheritdoc}
     *
     * @param InputInterface  $input  console input
     * @param OutputInterface $output console output
     * @return int exit code
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($this->command === null) {
            $this->command = $this->getApplication()->find(
                $input->getArgument('command_name')
            );
        }

        $helper = new DescriptorHelper();
        $helper->describe($output, $this->command);

        if ($this->command instanceof HelpCommand) {
            $output->writeln('');
            $output->writeln('<comment>Available commands:</comment>');

            $width = 12;
            foreach ($this->getApplication()->all() as $cmd) {
                $spacingWidth = $width - strlen($cmd->getName());
                $output->writeln(sprintf(
                    '  <info>%s</info>%s%s',
                    $cmd->getName(),
                    str_repeat(' ', $spacingWidth),
                    $cmd->getDescription()
                ));
            }
        }
        $output->writeln('');

        $this->command = null;
    }
}
