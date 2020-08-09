<?php

namespace IpsLint\Hooks;

use IpsLint\Ips\Hook;
use IpsLint\Ips\AbstractResource;
use IpsLint\Lint\Error;
use IpsLint\Loggers;
use IpsLint\Utils\StringUtils;
use PhpParser\Lexer;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

final class HooksValidator {
    private const DEFAULT_CONF = [
        'check-renames' => true,
        'rename-ignored-names' => ['val', 'value', 'data', 'arg'],
    ];

    public const ERR_HOOK_FILE_DOESNT_EXIST = "H001";
    public const ERR_HOOK_PARSE_ERR = "H002";
    public const ERR_HOOK_REFLECTION_ERR = "H003";
    public const ERR_HOOK_PARENT_DOESNT_EXIST = "H004";
    public const ERR_HOOK_PARENT_METHOD_INCOMPATIBLE = "H101";
    public const ERR_HOOK_VISIBILITY_CHANGED = "H102";
    public const ERR_HOOK_METHOD_INCOMPATIBLE_RETURN_TYPE = "H103";
    public const ERR_HOOK_METHOD_MISSING_PARAMETER = "H104";
    public const ERR_HOOK_METHOD_EXTRA_REQUIRED_PARAMETER = "H105";
    public const ERR_HOOK_METHOD_INCOMPATIBLE_PARAMETER_TYPE = "H106";
    public const ERR_HOOK_METHOD_PARAMETER_NAME_CHANGED = "H107";
    public const ERR_HOOK_METHOD_PARAMETER_VALUE_CHANGED = "H108";
    public const ERR_HOOK_PARENT_METHOD_DOESNT_EXIST = "H201";

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
     * @return Error[] Validation errors
     */
    public function validate(): array {
        $errors = [];
        foreach ($this->resources as $resource) {
            Loggers::main()->debug("Processing resource {$resource->getName()}");
            foreach ($resource->getHooks() as $hook) {
                Loggers::main()->debug("Processing hook {$hook->getName()} from {$resource->getName()}");
                foreach ($this->validateHook($hook, $resource) as $error) {
                    $errors[] = $error;
                }
            }
        }
        return $errors;
    }

    /**
     * @return Error[] Validation errors
     */
    private function validateHook(Hook $hook, AbstractResource $resource): array {
        if (!file_exists($hook->getPath())) {
            return [new Error(
                "Hook file {$hook->getPath()} does not exist",
                self::ERR_HOOK_FILE_DOESNT_EXIST,
                $resource,
                $resource->getHooksFilePath())];
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
            return [new Error(
                "ParseError while parsing hook: {$e->getMessage()}",
                self::ERR_HOOK_PARSE_ERR,
                $resource,
                $hook->getPath(),
                $e->getLine())];
        }

        try {
            $hookClass = new \ReflectionClass($randomisedName);
        } catch (\ReflectionException $e) {
            return [new Error(
                "ReflectionException while processing hook: {$e}",
                self::ERR_HOOK_REFLECTION_ERR,
                $resource,
                $hook->getPath())];
        }
        if (mb_stristr($hookClass->getDocComment(), '@ips-lint ignore')) {
            return null;
        }

        try {
            $originalClass = new \ReflectionClass($hook->getClass());
        } catch (\ReflectionException $e) {
            return [new Error(
                "Hooked class {$hook->getClass()} does not exist",
                self::ERR_HOOK_PARENT_DOESNT_EXIST,
                $resource,
                $hook->getPath())];
        }

        $errors = [];
        foreach ($hookClass->getMethods() as $hookMethod) {
            if (!mb_stristr($hookMethod->getDocComment(), '@ips-lint ignore')) {
                try {
                    $originalMethod = $originalClass->getMethod($hookMethod->getName());
                    $result = $this->validateHookSignature(
                        $hookMethod, $originalMethod, $resource, $hook);
                    if ($result !== null) {
                        $errors[] = $result;
                    }
                } catch (\ReflectionException $e) {
                    Loggers::main()->info(
                        "Not validating signature of {$hookMethod->getName()} because it doesn't exist in the parent");
                }

                $methodBody = StringUtils::extractLines(
                    $hookFile,
                    $hookMethod->getStartLine(),
                    $hookMethod->getEndLine());
                $parentUsages = $this->findParentUsages($methodBody, $hookMethod->getStartLine());
                foreach ($parentUsages as $parentUsage) {
                    if (!$originalClass->hasMethod($parentUsage['method'])) {
                        $errors[] = new Error(
                            "Method {$hookMethod->getName()} does not exist in {$hook->getClass()}",
                            self::ERR_HOOK_PARENT_METHOD_DOESNT_EXIST,
                            $resource,
                            $hook->getPath(),
                            $parentUsage['line']);
                    }
                }
            }
        }
        return $errors;
    }

    private function validateHookSignature(
            \ReflectionMethod $hookMethod,
            \ReflectionMethod $originalMethod,
            AbstractResource $resource,
            Hook $hook): ?Error {
        if ($originalMethod->isPrivate()) {
            return new Error(
                "Method {$hookMethod->getName()} is private in {$originalMethod->getDeclaringClass()->getName()}",
                self::ERR_HOOK_PARENT_METHOD_INCOMPATIBLE,
                $resource,
                $hook->getPath(),
                $hookMethod->getStartLine());
        }
        if ($originalMethod->isPublic() && !$hookMethod->isPublic()) {
            return new Error(
                "Method {$hookMethod->getName()} is public in {$originalMethod->getDeclaringClass()->getName()}, " .
                    "but not in the hook",
                self::ERR_HOOK_VISIBILITY_CHANGED,
                $resource,
                $hook->getPath(),
                $hookMethod->getStartLine());
        }
        if ($originalMethod->isStatic() && !$hookMethod->isStatic()) {
            return new Error(
                "Method {$hookMethod->getName()} is static in {$originalMethod->getDeclaringClass()->getName()}, " .
                    "but not in the hook",
                self::ERR_HOOK_PARENT_METHOD_INCOMPATIBLE,
                $resource,
                $hook->getPath(),
                $hookMethod->getStartLine());
        }
        if (!$originalMethod->isStatic() && $hookMethod->isStatic()) {
            return new Error(
                "{$hookMethod->getName()} is an instance method in " .
                    "{$originalMethod->getDeclaringClass()->getName()}, but static in the hook",
                self::ERR_HOOK_PARENT_METHOD_INCOMPATIBLE,
                $resource,
                $hook->getPath(),
                $hookMethod->getStartLine());
        }
        if ($originalMethod->hasReturnType() && !$hookMethod->hasReturnType()) {
            return new Error(
                "{$hookMethod->getName()} has a return type of {$originalMethod->getReturnType()->getName()} in " .
                    "{$originalMethod->getDeclaringClass()->getName()}, but no return type in the hook",
                self::ERR_HOOK_METHOD_INCOMPATIBLE_RETURN_TYPE,
                $resource,
                $hook->getPath(),
                $hookMethod->getStartLine());
        }

        return $this->validateParameters($hookMethod, $originalMethod, $resource, $hook);
    }

    private function validateParameters(
            \ReflectionMethod $hookMethod,
            \ReflectionMethod $originalMethod,
            AbstractResource $resource,
            Hook $hook): ?Error {
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
                return new Error(
                    "Method {$originalMethod->getName()} is missing parameters {$paramNamesString} (defined in " .
                        "{$originalMethod->getDeclaringClass()->getName()})",
                    self::ERR_HOOK_METHOD_MISSING_PARAMETER,
                    $resource,
                    $hook->getPath(),
                    $hookMethod->getStartLine());
            }
            $method = "{$originalMethod->getDeclaringClass()->getName()}::{$originalMethod->getName()}";
            if (!$param[0]->isOptional()) {
                if ($param[1] === null) {
                    return new Error(
                        "Parameter {$param[0]->getName()} does not exist in {$method}, but is required in the hook",
                        self::ERR_HOOK_METHOD_EXTRA_REQUIRED_PARAMETER,
                        $resource,
                        $hook->getPath(),
                        $hookMethod->getStartLine());
                }
                if ($param[1]->isOptional()) {
                    return new Error(
                        "Parameter {$param[0]->getName()} is optional in {$method}, but is required in the hook",
                        self::ERR_HOOK_METHOD_EXTRA_REQUIRED_PARAMETER,
                        $resource,
                        $hook->getPath(),
                        $hookMethod->getStartLine());
                }
            } else if ($param[1] !== null && $param[1]->isOptional()) {
                $hookDefault = $param[0]->getDefaultValue();
                $originalDefault = $param[1]->getDefaultValue();
                if ($hookDefault !== $originalDefault) {
                    return new Error(
                        "Parameter {$param[0]->getName()} has default value " . print_r($originalDefault, true) .
                                " in {$method}, but " . print_r($hookDefault, true) . ' in the hook',
                        self::ERR_HOOK_METHOD_PARAMETER_VALUE_CHANGED,
                        $resource,
                        $hook->getPath(),
                        $hookMethod->getStartLine());
                }
            }
            if ($param[0]->hasType() && !$param[1]->hasType()) {
                return new Error(
                    "Parameter {$param[0]->getName()} is untyped in {$method}, but has type " .
                        "{$param[0]->getType()->getName()} in the hook",
                    self::ERR_HOOK_METHOD_INCOMPATIBLE_PARAMETER_TYPE,
                    $resource,
                    $hook->getPath(),
                    $hookMethod->getStartLine());
            }
            if (
                $checkRenames &&
                $param[1] &&
                $param[0]->getName() !== $param[1]->getName() &&
                !in_array($param[0]->getName(), $this->conf['rename-ignored-names']) &&
                !in_array($param[1]->getName(), $this->conf['rename-ignored-names'])) {
                return new Error(
                    "Hook parameter of {$param[0]->getName()} does not match original parameter of " .
                        "{$param[1]->getName()} declared in {$method}",
                    self::ERR_HOOK_METHOD_PARAMETER_NAME_CHANGED,
                    $resource,
                    $hook->getPath(),
                    $hookMethod->getStartLine());
            }
        }
        return null;
    }

    private function findParentUsages(string $methodBody, int $firstLineNum): array {
        $lexer = new Lexer(['usedAttributes' => ['startLine']]);
        $parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7, $lexer);
        try {
            $ast = $parser->parse("<?php class _fake_class_ {\n{$methodBody}\n}");
        } catch (\Exception $e) {
            Loggers::main()->error("Failed to parse AST: {$e->getMessage()}\n{$e->getTraceAsString()}");
            return [];
        }

        $visitor = new ParentVisitor($firstLineNum - 1);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->getParentCalls();
    }
}
