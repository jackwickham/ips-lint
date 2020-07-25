<?php

namespace IpsLint\Lint;

use IpsLint\Validate\HooksValidator;
use Symfony\Component\Console\Formatter\OutputFormatterInterface;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;

final class Formatter {
    public const LEVEL_IGNORE = 0;
    public const LEVEL_WARN = 1;
    public const LEVEL_ERROR = 2;

    /**
     * @var array[string]Error[]
     */
    private array $errorsByFile = [];

    /**
     * @param Error[] $allErrors
     */
    public function __construct(array $allErrors) {
        foreach ($allErrors as $error) {
            if ($this->errorSeverity($error) > self::LEVEL_IGNORE) {
                $this->errorsByFile[$error->getFile() ?? ''][] = $error;
            }
        }
        foreach ($this->errorsByFile as $errorsInFile) {
            usort($errorsInFile, function($a, $b) {
                return $this->errorSeverity($b) - $this->errorSeverity($a);
            });
        }
    }

    public function formatForConsole(): string {
        $result = [];
        foreach ($this->errorsByFile as $file => $errors) {
            $result[] = $file;
            /** @var Error $error */
            foreach ($errors as $error) {
                $level = $this->errorSeverity($error);
                $line = \str_pad($error->getLine() ?? '', 4, ' ', STR_PAD_LEFT);
                $col = $error->getCol() === null ? '    ' : (':' . \str_pad($error->getCol(), 3, ' ', STR_PAD_RIGHT));

                if ($level === self::LEVEL_ERROR) {
                    $result[] = "{$line}{$col} <linterror>ERROR</linterror> {$error->getMessage()} ({$error->getCode()})";
                } else if ($level === self::LEVEL_WARN) {
                    $result[] = "{$line}{$col} <lintwarning>WARN</lintwarning>  {$error->getMessage()} ({$error->getCode()})";
                } else {
                    throw new \LogicException("Unknown level {$level}");
                }
            }
        }
        return implode("\n", $result);
    }

    public function setConsoleStyles(OutputFormatterInterface $outputFormatter): void {
        $errorStyle = new OutputFormatterStyle('red');
        $outputFormatter->setStyle('linterror', $errorStyle);

        $warnStyle = new OutputFormatterStyle('yellow');
        $outputFormatter->setStyle('lintwarning', $warnStyle);
    }

    private function errorSeverity(Error $error): int {
        // TODO: Logic
        if ($error->getCode() === HooksValidator::ERR_HOOK_PARENT_METHOD_DOESNT_EXIST) {
            return self::LEVEL_WARN;
        }
        return self::LEVEL_ERROR;
    }
}
