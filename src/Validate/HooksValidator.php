<?php

namespace IpsLint\Validate;

use IpsLint\Ips\Hook;
use IpsLint\Ips\AbstractResource;
use IpsLint\Loggers;

final class HooksValidator {
    /**
     * @var AbstractResource[]
     */
    private array $resources;

    public function __construct(array $resources) {
        $this->resources = $resources;
    }

    /**
     * @return string[] Validation errors
     */
    public function validate(): array {
        $errors = [];
        foreach ($this->resources as $resource) {
            Loggers::main()->debug("Processing resource {$resource->getName()}");
            if (count($this->resources) === 1) {
                $resourcePrefix = '';
            } else {
                $resourcePrefix = $resource->getName() . ' ';
            }
            foreach ($resource->getHooks() as $hook) {
                Loggers::main()->debug("Processing hook {$hook->getName()} from {$resource->getName()}");
                $prefix = $resourcePrefix . $hook->getName() . ': ';
                $results = $this->validateHook($hook);
                foreach ($results as $result) {
                    $errors[] = $prefix . $result;
                }
            }
        }
        return $errors;
    }

    /**
     * @return string[] Validation errors
     */
    private function validateHook(Hook $hook): array {
        if (!file_exists($hook->getPath())) {
            return ["Hook file {$hook->getPath()} does not exist"];
        }

        if ($hook->isThemeHook()) {
            return [];
        }

        $hookFile = file_get_contents($hook->getPath());
        $randomisedName = uniqid('hook_', false);
        $hookFile = preg_replace('/class \S+ extends _HOOK_CLASS_/', "class {$randomisedName}", $hookFile);

        try {
            eval($hookFile);
        } catch (\ParseError $e) {
            return ["ParseError while parsing hook: {$e->getMessage()}"];
        }

        try {
            $hookClass = new \ReflectionClass($randomisedName);
        } catch (\ReflectionException $e) {
            return ["ReflectionException while processing hook: {$e}"];
        }
        if (mb_stristr($hookClass->getDocComment(), '@ips-lint ignore')) {
            return null;
        }

        try {
            $originalClass = new \ReflectionClass($hook->getClass());
        } catch (\ReflectionException $e) {
            return ["Hooked class {$hook->getClass()} does not exist"];
        }

        $errors = [];
        foreach ($hookClass->getMethods() as $hookMethod) {
            if (!mb_stristr($hookMethod->getDocComment(), '@ips-lint ignore')) {
                try {
                    $originalMethod = $originalClass->getMethod($hookMethod->getName());
                } catch (\ReflectionException $e) {
                    $startLine = $hookMethod->getStartLine() - 1;
                    $numLines = $hookMethod->getEndLine() - $startLine;
                    preg_match("/^(?:.*\n){{$startLine}}((?:.*\n){{$numLines}}.*)/", $hookFile, $matches);
                    if (isset($matches[1]) && !mb_stristr($matches[1], 'parent::')) {
                        Loggers::main()->info(
                                "{$hookMethod->getName()} does not exist in {$hook->getClass()}, but "
                                        . "{$hook->getName()} doesn't call parent - ignoring");
                        continue;
                    }
                    $errors[] = "Method {$hookMethod->getName()} does not exist in {$hook->getClass()}";
                    continue;
                }
                $result = $this->validateHookMethod($hookMethod, $originalMethod);
                if ($result !== null) {
                    $errors[] = $hookMethod->getName() . ' ' . $result;
                }
            }
        }
        return $errors;
    }

    private function validateHookMethod(\ReflectionMethod $hookMethod, \ReflectionMethod $originalMethod): ?string {
        if ($originalMethod->isPrivate()) {
            return "is private in {$originalMethod->getDeclaringClass()->getName()}";
        }
        if ($originalMethod->isPublic() && !$hookMethod->isPublic()) {
            return "is public in {$originalMethod->getDeclaringClass()->getName()}, but not in the hook";
        }
        if ($originalMethod->isStatic() && !$hookMethod->isStatic()) {
            return "is static in {$originalMethod->getDeclaringClass()->getName()}, but not in the hook";
        }
        if (!$originalMethod->isStatic() && $hookMethod->isStatic()) {
            return "is an instance method in {$originalMethod->getDeclaringClass()->getName()}, but static in the hook";
        }
        // TODO: Validate parameters
        return null;
    }
}
