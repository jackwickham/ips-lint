<?php

namespace IpsLint\Ips;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;

abstract class AbstractResource implements LoggerAwareInterface {
    private string $path;
    protected LoggerInterface $logger;

    protected function __construct(string $path) {
        $this->path = $path;
    }

    public function getPath(): string {
        return $this->path;
    }

    /**
     * @return Hook[]
     */
    public function getHooks(): array {
        if (!file_exists($this->getHooksFilePath())) {
            $this->logger->warning("No hooks.json file found in {$this->getPath()}");
            return [];
        }
        $hookData = json_decode(file_get_contents($this->getHooksFilePath()), true, 512, JSON_THROW_ON_ERROR);
        $hooks = [];
        foreach ($hookData as $name => $data) {
            $hooks[] = new Hook(
                    $name,
                    $data['type'] === 'S',
                    $data['class'],
                    $this->getPath() . "hooks/{$name}.php");
        }
        return $hooks;
    }

    public function getName() {
        return basename($this->path);
    }

    protected abstract function getHooksFilePath();

    public function setLogger(LoggerInterface $logger) {
        $this->logger = $logger;
    }
}
