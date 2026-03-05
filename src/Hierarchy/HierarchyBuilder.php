<?php

declare(strict_types=1);

namespace Lmcc\Hierarchy;

final class HierarchyBuilder
{
    /**
     * Build a hierarchy tree from semantic units using BFS partitioning by indent level.
     *
     * @param SemanticUnit[] $units
     */
    public function build(array $units): HierarchyNode
    {
        $root = new HierarchyNode(depth: 0);

        if ($units === []) {
            return $root;
        }

        /** @var array<array{HierarchyNode, int, int}> $queue */
        $queue = [[$root, 0, count($units) - 1]];

        while (!empty($queue)) {
            [$node, $s, $e] = array_shift($queue);

            if ($s === $e) {
                $node->addChild(new HierarchyNode(depth: $node->depth + 1, unit: $units[$s]));
                continue;
            }

            $minIndent = $this->minIndentInRange($units, $s, $e);
            $allSame = $this->allSameIndent($units, $s, $e, $minIndent);

            if ($allSame) {
                for ($i = $s; $i <= $e; $i++) {
                    $node->addChild(new HierarchyNode(depth: $node->depth + 1, unit: $units[$i]));
                }
                continue;
            }

            $i = $s;
            while ($i <= $e) {
                if ($units[$i]->indentLevel === $minIndent) {
                    $node->addChild(new HierarchyNode(depth: $node->depth + 1, unit: $units[$i]));
                    $i++;
                } else {
                    $groupStart = $i;
                    while ($i <= $e && $units[$i]->indentLevel > $minIndent) {
                        $i++;
                    }
                    $groupEnd = $i - 1;
                    $internal = new HierarchyNode(depth: $node->depth + 1);
                    $node->addChild($internal);
                    $queue[] = [$internal, $groupStart, $groupEnd];
                }
            }
        }

        return $root;
    }

    /**
     * @param SemanticUnit[] $units
     */
    private function minIndentInRange(array $units, int $s, int $e): int
    {
        $min = PHP_INT_MAX;
        for ($i = $s; $i <= $e; $i++) {
            if ($units[$i]->indentLevel < $min) {
                $min = $units[$i]->indentLevel;
            }
        }
        return $min;
    }

    /**
     * @param SemanticUnit[] $units
     */
    private function allSameIndent(array $units, int $s, int $e, int $indent): bool
    {
        for ($i = $s; $i <= $e; $i++) {
            if ($units[$i]->indentLevel !== $indent) {
                return false;
            }
        }
        return true;
    }
}
