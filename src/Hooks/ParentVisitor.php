<?php

namespace IpsLint\Hooks;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

final class ParentVisitor extends NodeVisitorAbstract {
    private array $parentCalls = [];
    private int $firstLineNum;

    public function __construct($firstLineNum) {
        $this->firstLineNum = $firstLineNum;
    }

    public function enterNode(Node $node) {
        if (
                $node instanceof Node\Expr\StaticCall &&
                $node->class instanceof Node\Name &&
                $node->class->parts == ['parent']) {
            $call = ['method' => $node->name->name];
            if ($node->getStartLine() > -1) {
                $call['line'] = $this->firstLineNum + $node->getStartLine() - 1;
            }
            $this->parentCalls[] = $call;
        }
    }

    public function getParentCalls(): array {
        return $this->parentCalls;
    }
}
