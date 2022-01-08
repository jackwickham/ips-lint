<?php

namespace IpsLint\Templates;

use IpsLint\Ips\AbstractResource;
use IpsLint\Lint\Error;
use IpsLint\Loggers;

final class TemplatesValidator {
    public const ERR_TEMPLATE_INTERPOLATION_MISSING_BRACES = "T001";

    /**
     * @var AbstractResource[]
     */
    private array $resources;

    private array $conf;

    public function __construct(array $resources) {
        $this->resources = $resources;
        $this->conf = [];
    }

    /**
     * @return Error[] Validation errors
     */
    public function validate(): array {
        $errors = [];
        foreach ($this->resources as $resource) {
            Loggers::main()->debug("Processing resource {$resource->getName()}");
            foreach ($resource->getTemplates() as $template) {
                $templateName = basename($template);
                Loggers::main()->debug("Processing template {$templateName} from {$resource->getName()}");
                foreach ($this->validateTemplate($template, $resource) as $error) {
                    $errors[] = $error;
                }
            }
        }
        return $errors;
    }

    private function validateTemplate(string $templatePath, AbstractResource $resource): array {
        // Remove header line from template
        $template = preg_replace('/^.*\n/', '', file_get_contents($templatePath));
        $compiled = \IPS\Theme::compileTemplate($template, 'template');

        $missingBraces = BraceWrappedInterpolationVisitor::checkCode($compiled);

        $errors = [];
        foreach ($missingBraces as $err) {
            $errors[] = new Error(
                "Interpolated expression must be wrapped in braces: {$err}",
                self::ERR_TEMPLATE_INTERPOLATION_MISSING_BRACES,
                $resource,
                $templatePath);
        }
        return $errors;
    }
}
