<?php

namespace App\Support;

use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use SebastianBergmann\Complexity\CyclomaticComplexityCalculatingVisitor;

/**
 * Collects cyclomatic complexity and line range per method, keyed as
 * "Fully\Qualified\Class::method" so results join onto a crap4j report.
 *
 * Needs NameResolver and ParentConnectingVisitor ahead of it in the traverser.
 *
 * The counter is php-code-coverage's own, so the numbers agree with the CRAP it
 * reports. Its wrapping visitor is not reused because that one asserts a method's
 * parent is a class or a trait, which blows up on enums.
 */
class MethodComplexityVisitor extends NodeVisitorAbstract
{
    /**
     * @var array<string, array{range: array{int, int}, complexity: int}>
     */
    public array $measured = [];

    public function enterNode(Node $node): ?int
    {
        if (! $node instanceof ClassMethod && ! $node instanceof Function_) {
            return null;
        }

        $name = $node instanceof ClassMethod ? $this->methodName($node) : $this->functionName($node);

        if ($name === null) {
            return null;
        }

        $this->measured[$name] = [
            'range' => [$node->getStartLine(), $node->getEndLine()],
            'complexity' => $this->complexity($node),
        ];

        // Closures nested in the body count toward this method, not as entries
        // of their own -- same as php-code-coverage does it.
        return NodeVisitor::DONT_TRAVERSE_CHILDREN;
    }

    private function complexity(ClassMethod|Function_ $node): int
    {
        $counter = new CyclomaticComplexityCalculatingVisitor;
        $traverser = new NodeTraverser;
        $traverser->addVisitor($counter);
        $traverser->traverse($node->getStmts() ?? []);

        return $counter->cyclomaticComplexity();
    }

    private function methodName(ClassMethod $node): ?string
    {
        $parent = $node->getAttribute('parent');

        if ($node->isAbstract() || $parent instanceof Interface_) {
            return null;
        }

        if (! $parent instanceof Class_ && ! $parent instanceof Trait_ && ! $parent instanceof Enum_) {
            return null;
        }

        // Anonymous classes have no name to join on, so they are left out.
        if ($parent->getAttribute('parent') instanceof New_ || ! isset($parent->namespacedName)) {
            return null;
        }

        return $parent->namespacedName->toString().'::'.$node->name->toString();
    }

    private function functionName(Function_ $node): ?string
    {
        return isset($node->namespacedName) ? $node->namespacedName->toString() : null;
    }
}
