<?php

namespace IpsLint\Templates;

use IpsLint\Loggers;
use PhpParser\Lexer;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;
use PhpParser\PrettyPrinterAbstract;

final class BraceWrappedInterpolationVisitor extends NodeVisitorAbstract {
    private int $lastTokenIndex = 0;
    private bool $inHeredocString = false;
    private array $violations = [];
    private PrettyPrinterAbstract $prettyPrinter;

    public function __construct() {
        $this->prettyPrinter = new PrettyPrinter\Standard();
    }

    public function enterNode(Node $node) {
        if ($this->isHeredoc($node)) {
            $this->inHeredocString = true;
        } elseif ($this->inHeredocString && !($node instanceof Node\Scalar\EncapsedStringPart)) {
            $this->inHeredocString = false;
            $node->setAttribute('heredocChild', true);
            if ($node->getStartTokenPos() !== $this->lastTokenIndex + 2) {
                // There wasn't one token between previous and current, so there wasn't a brace
                $this->violations[] = $this->prettyPrinter->prettyPrintExpr($node);
            }
        }

        $this->lastTokenIndex = $node->getStartTokenPos();
    }

    public function leaveNode(Node $node) {
        if ($this->isHeredoc($node)) {
            $this->inHeredocString = false;
        } elseif ($node->getAttribute('heredocChild', false)) {
            $this->inHeredocString = true;
        }
    }

    private function isHeredoc(Node $node) {
        return $node instanceof Node\Scalar\Encapsed &&
            $node->getAttribute('kind') === Node\Scalar\String_::KIND_HEREDOC;
    }

    public static function checkCode(string $method) {
        $lexer = new Lexer(['usedAttributes' => ['startTokenPos']]);
        $parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7, $lexer);
        try {
            $ast = $parser->parse("<?php class _fake_class_ {\n{$method}\n}");
        } catch (\Exception $e) {
            Loggers::main()->error("Failed to parse AST: {$e->getMessage()}\n{$e->getTraceAsString()}");
            return [];
        }

        $visitor = new BraceWrappedInterpolationVisitor();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->violations;
    }
}
