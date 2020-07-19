<?php

namespace IpsLint\Ips;

final class Hook {
    private string $name;
    private bool $themeHook;
    private string $class;
    private string $path;

    public function __construct(string $name, bool $themeHook, string $class, string $path) {
        $this->name = $name;
        $this->themeHook = $themeHook;
        $this->class = $class;
        $this->path = $path;
    }

    /**
     * @return string
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * @return bool
     */
    public function isThemeHook(): bool {
        return $this->themeHook;
    }

    /**
     * @return string
     */
    public function getClass(): string {
        return $this->class;
    }

    public function getPath(): string {
        return $this->path;
    }
}
