<?php

namespace IpsLint\Ips;

use IpsLint\Loggers;

abstract class AbstractResource {
    private string $path;

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
            Loggers::main()->warning("No hooks.json file found in {$this->getPath()}");
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
}
