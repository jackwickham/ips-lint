#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';

use Monolog\Logger;
use Symfony\Component\Console\Application;

$logger = new Logger('stderr');
$logger->pushHandler(new \Monolog\Handler\StreamHandler('php://stderr', Logger::DEBUG));

$application = new Application();

$application->add(new \IpsLint\Command\ValidateHooksCommand($logger));

$application->run();