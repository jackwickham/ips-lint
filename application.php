#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';

use Monolog\Logger;
use Symfony\Component\Console\Application;

$mainLogger = new Logger('stderr');
$mainLogger->pushHandler(new \Monolog\Handler\StreamHandler('php://stderr', Logger::INFO));

\IpsLint\Loggers::init($mainLogger);

$application = new Application();

$application->add(new \IpsLint\Command\ValidateHooksCommand());
$application->add(new \IpsLint\Command\ValidateTemplatesCommand());

$application->run();
