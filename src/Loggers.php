<?php

namespace IpsLint;

use Psr\Log\LoggerInterface;

final class Loggers {
    private static ?Loggers $instance = null;

    private LoggerInterface $mainLogger;

    private function __construct(LoggerInterface $mainLogger) {
        $this->mainLogger = $mainLogger;
        self::$instance = $this;
    }

    public static function main(): LoggerInterface {
        return self::getInstanceOrThrow()->mainLogger;
    }

    public static function init(LoggerInterface $mainLogger): Loggers {
        if (self::$instance !== null) {
            throw new \LogicException("Cannot initalise loggers multiple times");
        }
        self::$instance = new Loggers($mainLogger);
        return self::$instance;
    }

    private static function getInstanceOrThrow(): Loggers {
        if (self::$instance === null) {
            throw new \LogicException("Loggers have not been initialised yet");
        }
        return self::$instance;
    }
}
