<?php

namespace IpsLint\Lint;

use IpsLint\Ips\AbstractResource;

class Error {
    private string $message;
    private string $code;
    private AbstractResource $resource;
    private ?string $file;
    private ?int $line;
    private ?int $col;

    public function __construct(
            string $message,
            string $code,
            AbstractResource $resource,
            ?string $file = null,
            ?int $line = null,
            ?int $col = null) {
        $this->message = $message;
        $this->code = $code;
        $this->resource = $resource;
        $this->file = $file;
        $this->line = $line;
        $this->col = $col;
    }

    public function getMessage(): string {
        return $this->message;
    }

    public function getCode(): string {
        return $this->code;
    }

    public function getResource(): AbstractResource {
        return $this->resource;
    }

    public function getFile(): ?string {
        return $this->file;
    }

    public function getLine(): ?int {
        return $this->line;
    }

    public function getCol(): ?int {
        return $this->col;
    }
}
