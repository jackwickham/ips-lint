<?php

namespace IpsLint\Ips;

final class Application extends AbstractResource {
    public function __construct(string $path) {
        parent::__construct($path);
    }

    public function getHooksFilePath() {
        return $this->getPath() . 'data/hooks.json';
    }
}
