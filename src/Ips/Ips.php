<?php

namespace IpsLint\Ips;

final class Ips {
    private string $path;

    private function __construct(string $path) {
        $this->path = $path;
    }

    /**
     * @throws \RuntimeException If no suitable path or init.php file can be found
     */
    public static function init(?string $path, ?string $resource = null, bool $loadHooks = false): Ips {
        if ($path === null) {
            $path = self::searchForRootPath($resource ?? getcwd());
        } else {
            $path = self::normalisePath($path);
        }
        if (!file_exists($path . 'init.php')) {
            throw new \RuntimeException("Failed to find init.php in $path");
        }

        define('RECOVERY_MODE', !$loadHooks);
        require $path . "init.php";
        restore_error_handler();
        restore_exception_handler();

        return new Ips($path);
    }

    /**
     * @throws \RuntimeException If no suitable path can be found
     */
    private static function searchForRootPath(string $path): string {
        for ($i = 0; $i < 3; $i++) {
            $path = self::normalisePath($path);
            if (file_exists($path . "init.php")) {
                return $path;
            }
            $path = dirname($path);
        }
        throw new \RuntimeException('Failed to find IPS root path - try specifying it explicitly');
    }

    /**
     * @return AbstractResource[]
     * @throws \RuntimeException if an invalid plugin is discovered
     */
    public function findResources(?string $path): array {
        $path = self::normalisePath($path ?? $this->path);
        if (file_exists($path . 'init.php')) {
            return [...$this->findResources($path . 'applications/'), ...$this->findResources($path . 'plugins/')];
        }
        if (file_exists($path . "Application.php")) {
            $app = new Application($path);
            return [$app];
        }
        if (file_exists($path . "dev")) {
            $plugin = new Plugin($path);
            return [$plugin];
        }
        if (file_exists($path . "hooks")) {
            //throw new \RuntimeException("No dev directory found in $path");
            return [];
        }

        $results = [];
        foreach (new \DirectoryIterator($path) as $fileInfo) {
            if ($fileInfo->isDir() && $fileInfo->getFilename()[0] !== '.') {
                foreach ($this->findResources($fileInfo->getPathname()) as $resource) {
                    $results[] = $resource;
                }
            }
        }
        return $results;
    }

    private static function normalisePath(string $path): string {
        return rtrim($path, '/') . '/';
    }
}
