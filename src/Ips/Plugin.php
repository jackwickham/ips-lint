<?php

namespace IpsLint\Ips;

final class Plugin extends AbstractResource {
    public function __construct(string $path) {
        parent::__construct($path);
    }

    public function getHooksFilePath() {
        return $this->getPath() . 'dev/hooks.json';
    }
}
