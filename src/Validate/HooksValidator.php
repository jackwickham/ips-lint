<?php

namespace IpsLint\Validate;

use IpsLint\Ips\Hook;
use IpsLint\Ips\AbstractResource;
use IpsLint\Loggers;

final class HooksValidator {
    private const DEFAULT_CONF = [
        'check-renames' => true,
        'rename-ignored-names' => ['val', 'value', 'data', 'arg'],
    ];

    /**
     * @var AbstractResource[]
     */
    private array $resources;

    private array $conf;

    public function __construct(array $resources) {
        $this->resources = $resources;
        $this->conf = self::DEFAULT_CONF;
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
                foreach ($this->validateHook($hook) as $error) {
                    $errors[] = $prefix . $error;
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
        $hookFile = preg_replace(
            '/class \S+ extends _HOOK_CLASS_/',
            "class {$randomisedName} extends \IpsLint\Utils\EmptyClass",
            $hookFile);

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
                    $errors[] = $result;
                }
            }
        }
        return $errors;
    }

    private function validateHookMethod(\ReflectionMethod $hookMethod, \ReflectionMethod $originalMethod): ?string {
        if ($originalMethod->isPrivate()) {
            return "Method {$hookMethod->getName()} is private in {$originalMethod->getDeclaringClass()->getName()}";
        }
        if ($originalMethod->isPublic() && !$hookMethod->isPublic()) {
            return "Method {$hookMethod->getName()} is public in {$originalMethod->getDeclaringClass()->getName()}, " .
                "but not in the hook";
        }
        if ($originalMethod->isStatic() && !$hookMethod->isStatic()) {
            return "Method {$hookMethod->getName()} is static in {$originalMethod->getDeclaringClass()->getName()}, " .
                "but not in the hook";
        }
        if (!$originalMethod->isStatic() && $hookMethod->isStatic()) {
            return "{$hookMethod->getName()} is an instance method in " .
                "{$originalMethod->getDeclaringClass()->getName()}, but static in the hook";
        }
        if ($originalMethod->hasReturnType() && !$hookMethod->hasReturnType()) {
            return "{$hookMethod->getName()} has a return type of {$originalMethod->getReturnType()->getName()} in " .
                "{$originalMethod->getDeclaringClass()->getName()}, but no return type in the hook";
        }

        return $this->validateParameters($hookMethod, $originalMethod);
    }

    private function validateParameters(\ReflectionMethod $hookMethod, \ReflectionMethod $originalMethod): ?string {
        $checkRenames =
            $this->conf['check-renames'] && !mb_stristr($hookMethod->getDocComment(), "@ips-lint no-check-renames");
        $zipped = array_map(null, $hookMethod->getParameters(), $originalMethod->getParameters());
        /** @var $param \ReflectionParameter[] */
        foreach ($zipped as $param) {
            if ($param[0] === null) {
                $extraParams = array_slice($originalMethod->getParameters(), $param[1]->getPosition());
                $paramNames = [];
                /** @var $extraParam \ReflectionParameter */
                foreach ($extraParams as $extraParam) {
                    $paramNames[] = $extraParam->getName();
                }
                $paramNamesString = implode(", ", $paramNames);
                return "Method {$originalMethod->getName()} is missing parameters {$paramNamesString} (defined in " .
                    "{$originalMethod->getDeclaringClass()->getName()})";
            }
            $method = "{$originalMethod->getDeclaringClass()->getName()}::{$originalMethod->getName()}";
            if (!$param[0]->isOptional()) {
                if ($param[1] === null) {
                    return "Parameter {$param[0]->getName()} does not exist in {$method}, but is required in the hook";
                }
                if ($param[1]->isOptional()) {
                    return "Parameter {$param[0]->getName()} is optional in {$method}, but is required in the hook";
                }
            }
            if ($param[0]->hasType() && !$param[1]->hasType()) {
                return "Parameter {$param[0]->getName()} is untyped in {$method}, but has type " .
                    "{$param[0]->getType()->getName()} in the hook";
            }
            if (
                $checkRenames &&
                $param[1] &&
                $param[0]->getName() !== $param[1]->getName() &&
                !in_array($param[0]->getName(), $this->conf['rename-ignored-names']) &&
                !in_array($param[1]->getName(), $this->conf['rename-ignored-names'])) {
                return "Hook parameter of {$param[0]->getName()} does not match original parameter of " .
                    "{$param[1]->getName()} declared in {$method}";
            }
        }
        return null;
    }
}
