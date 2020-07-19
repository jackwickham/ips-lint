<?php

namespace IpsLint\Ips;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;

final class Ips implements LoggerAwareInterface {
	private string $path;
	private LoggerInterface $logger;

	private function __construct(string $path) {
		$this->path = $path;
	}

	/**
	 * @throws \RuntimeException If no suitable path or init.php file can be found
	 */
	public static function init(LoggerInterface $logger, ?string $path, ?string $resource = null, bool $loadHooks = false): Ips {
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
		return new Ips($path);
	}

	/**
	 * @throws \RuntimeException If no suitable path can be found
	 */
	private static function searchForRootPath(string $path): string {
		for ($i = 0; $i < 2; $i++) {
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
			$app->setLogger($this->logger);
			return [$app];
		}
		if (file_exists($path . "dev")) {
			$plugin = new Plugin($path);
			$plugin->setLogger($this->logger);
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

	public function setLogger(LoggerInterface $logger) {
		$this->logger = $logger;
	}
}
