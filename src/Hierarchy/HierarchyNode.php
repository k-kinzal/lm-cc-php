<?php

declare(strict_types=1);

namespace Lmcc\Hierarchy;

final class HierarchyNode
{
    /** @var HierarchyNode[] */
    private array $children = [];

    public function __construct(
        public readonly int $depth,
        public readonly ?SemanticUnit $unit = null,
    ) {
    }

    public function addChild(self $child): void
    {
        $this->children[] = $child;
    }

    /**
     * @return HierarchyNode[]
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    public function branchingFactor(): int
    {
        return count($this->children);
    }

    public function isLeaf(): bool
    {
        return empty($this->children);
    }

    /**
     * BFS traversal of entire subtree.
     *
     * @return HierarchyNode[]
     */
    public function allNodes(): array
    {
        $queue = [$this];
        $result = [];
        while (!empty($queue)) {
            $node = array_shift($queue);
            $result[] = $node;
            foreach ($node->getChildren() as $child) {
                $queue[] = $child;
            }
        }
        return $result;
    }
}
