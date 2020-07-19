<?php

namespace IpsLint\Command;

use IpsLint\Ips\Ips;
use IpsLint\Validate\HooksValidator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ValidateHooksCommand extends Command {
    protected static $defaultName = "validate-hooks";

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger) {
        parent::__construct();
        $this->logger = $logger;
    }

    protected function configure() {
        $this->setDescription("Validate that all of the hooks in an application, plugin or suite are correct");
        $this->addArgument("path", InputArgument::REQUIRED, "Path to the application, plugin or suite to validate")
            ->addArgument(
                "suite",
                InputArgument::OPTIONAL,
                "Path to the IPS install. If omitted, it will try to be inferred");
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $ips = Ips::init($this->logger, $input->getArgument("suite"), $input->getArgument("path"));
        $ips->setLogger($this->logger);

        $validator = new HooksValidator($ips->findResources($input->getArgument("path")));
        $validator->setLogger($this->logger);
        $errors = $validator->validate();

        $outputStyle = new OutputFormatterStyle('red');
        $output->getFormatter()->setStyle('linterror', $outputStyle);
        foreach ($errors as $error) {
            $output->writeln("<linterror>{$error}</linterror>");
        }

        return count($errors) ? Command::FAILURE : Command::SUCCESS;
    }
}
