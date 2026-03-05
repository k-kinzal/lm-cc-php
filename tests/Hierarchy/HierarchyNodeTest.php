<?php

declare(strict_types=1);

namespace Lmcc\Tests\Hierarchy;

use Lmcc\Hierarchy\HierarchyNode;
use Lmcc\Hierarchy\SemanticUnit;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HierarchyNodeTest extends TestCase
{
    #[Test]
    public function allNodes_returns_bfs_order(): void
    {
        $root = new HierarchyNode(depth: 0);
        $child1 = new HierarchyNode(depth: 1);
        $child2 = new HierarchyNode(depth: 1);
        $grandchild = new HierarchyNode(depth: 2);

        $root->addChild($child1);
        $root->addChild($child2);
        $child1->addChild($grandchild);

        $all = $root->allNodes();

        self::assertCount(4, $all);
        self::assertSame($root, $all[0]);
        self::assertSame($child1, $all[1]);
        self::assertSame($child2, $all[2]);
        self::assertSame($grandchild, $all[3]);
    }

    #[Test]
    public function isLeaf_correct_for_leaf_vs_internal(): void
    {
        $root = new HierarchyNode(depth: 0);
        self::assertTrue($root->isLeaf());

        $child = new HierarchyNode(depth: 1);
        $root->addChild($child);

        self::assertFalse($root->isLeaf());
        self::assertTrue($child->isLeaf());
    }

    #[Test]
    public function branchingFactor_correct_count(): void
    {
        $root = new HierarchyNode(depth: 0);
        self::assertSame(0, $root->branchingFactor());

        $root->addChild(new HierarchyNode(depth: 1));
        self::assertSame(1, $root->branchingFactor());

        $root->addChild(new HierarchyNode(depth: 1));
        $root->addChild(new HierarchyNode(depth: 1));
        self::assertSame(3, $root->branchingFactor());
    }

    #[Test]
    public function addChild_children_correctly_added_and_retrievable(): void
    {
        $root = new HierarchyNode(depth: 0);
        $unit = new SemanticUnit(
            index: 0,
            content: 'test',
            indentLevel: 0,
            startOffset: 0,
            endOffset: 4,
            startLine: 1,
            endLine: 1,
        );
        $child = new HierarchyNode(depth: 1, unit: $unit);
        $root->addChild($child);

        $children = $root->getChildren();
        self::assertCount(1, $children);
        self::assertSame($child, $children[0]);
        self::assertSame($unit, $children[0]->unit);
        self::assertSame(1, $children[0]->depth);
    }
}
