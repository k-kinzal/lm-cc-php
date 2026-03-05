<?php

declare(strict_types=1);

namespace Lmcc\Tests\Hierarchy;

use Lmcc\Hierarchy\HierarchyBuilder;
use Lmcc\Hierarchy\HierarchyNode;
use Lmcc\Hierarchy\SemanticUnit;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HierarchyBuilderTest extends TestCase
{
    private HierarchyBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new HierarchyBuilder();
    }

    private function unit(int $index, int $indent): SemanticUnit
    {
        return new SemanticUnit(
            index: $index,
            content: "unit{$index}",
            indentLevel: $indent,
            startOffset: $index * 10,
            endOffset: $index * 10 + 5,
            startLine: $index + 1,
            endLine: $index + 1,
        );
    }

    #[Test]
    public function empty_input_returns_root_only(): void
    {
        $root = $this->builder->build([]);

        self::assertSame(0, $root->depth);
        self::assertSame([], $root->getChildren());
        self::assertSame(0, $root->branchingFactor());
    }

    #[Test]
    public function single_unit_creates_root_with_one_child(): void
    {
        $root = $this->builder->build([$this->unit(0, 0)]);

        self::assertSame(0, $root->depth);
        self::assertCount(1, $root->getChildren());
        self::assertSame(1, $root->getChildren()[0]->depth);
        self::assertTrue($root->getChildren()[0]->isLeaf());
    }

    #[Test]
    public function flat_units_all_become_direct_children(): void
    {
        $units = [
            $this->unit(0, 0),
            $this->unit(1, 0),
            $this->unit(2, 0),
        ];

        $root = $this->builder->build($units);

        self::assertSame(3, $root->branchingFactor());
        foreach ($root->getChildren() as $child) {
            self::assertSame(1, $child->depth);
            self::assertTrue($child->isLeaf());
        }
    }

    #[Test]
    public function nested_simple_creates_internal_node(): void
    {
        $units = [
            $this->unit(0, 0),
            $this->unit(1, 1),
            $this->unit(2, 1),
            $this->unit(3, 0),
        ];

        $root = $this->builder->build($units);

        self::assertSame(3, $root->branchingFactor());

        $children = $root->getChildren();
        self::assertTrue($children[0]->isLeaf());
        self::assertSame(1, $children[0]->depth);

        self::assertFalse($children[1]->isLeaf());
        self::assertSame(1, $children[1]->depth);
        self::assertSame(2, $children[1]->branchingFactor());
        foreach ($children[1]->getChildren() as $grandchild) {
            self::assertSame(2, $grandchild->depth);
            self::assertTrue($grandchild->isLeaf());
        }

        self::assertTrue($children[2]->isLeaf());
        self::assertSame(1, $children[2]->depth);
    }

    #[Test]
    public function deep_nesting_creates_chain(): void
    {
        $units = [
            $this->unit(0, 0),
            $this->unit(1, 1),
            $this->unit(2, 2),
            $this->unit(3, 3),
        ];

        $root = $this->builder->build($units);
        $allNodes = $root->allNodes();

        $maxDepth = 0;
        foreach ($allNodes as $node) {
            $maxDepth = max($maxDepth, $node->depth);
        }

        self::assertGreaterThanOrEqual(4, $maxDepth);
    }

    #[Test]
    public function mixed_indentation_correct_tree(): void
    {
        $units = [
            $this->unit(0, 0),
            $this->unit(1, 1),
            $this->unit(2, 1),
            $this->unit(3, 0),
            $this->unit(4, 1),
        ];

        $root = $this->builder->build($units);

        self::assertSame(4, $root->branchingFactor());
    }

    #[Test]
    public function depth_values_correct_at_every_level(): void
    {
        $units = [
            $this->unit(0, 0),
            $this->unit(1, 1),
            $this->unit(2, 0),
        ];

        $root = $this->builder->build($units);
        $allNodes = $root->allNodes();

        self::assertSame(0, $allNodes[0]->depth);

        foreach ($root->getChildren() as $child) {
            self::assertSame(1, $child->depth);
            foreach ($child->getChildren() as $grandchild) {
                self::assertSame(2, $grandchild->depth);
            }
        }
    }

    #[Test]
    public function branching_factors_correct(): void
    {
        $units = [
            $this->unit(0, 0),
            $this->unit(1, 0),
            $this->unit(2, 0),
        ];

        $root = $this->builder->build($units);

        self::assertSame(3, $root->branchingFactor());
        foreach ($root->getChildren() as $child) {
            self::assertSame(0, $child->branchingFactor());
        }
    }

    #[Test]
    public function proposition_b1_flat_grows_linearly(): void
    {
        $scores = [];
        foreach ([5, 10, 20] as $n) {
            $units = [];
            for ($i = 0; $i < $n; $i++) {
                $units[] = $this->unit($i, 0);
            }
            $root = $this->builder->build($units);
            $score = $this->computeScore($root, 0.8);
            $scores[$n] = $score;
        }

        $ratio1 = $scores[10] / $scores[5];
        $ratio2 = $scores[20] / $scores[10];

        self::assertGreaterThan(1.5, $ratio1);
        self::assertLessThan(2.5, $ratio1);
        self::assertGreaterThan(1.5, $ratio2);
        self::assertLessThan(2.5, $ratio2);
    }

    #[Test]
    public function proposition_b1_nested_grows_faster_than_linear(): void
    {
        $scores = [];
        foreach ([3, 6, 12] as $n) {
            $units = [];
            for ($i = 0; $i < $n; $i++) {
                $units[] = $this->unit($i, $i);
            }
            $root = $this->builder->build($units);
            $score = $this->computeScore($root, 0.8);
            $scores[$n] = $score;
        }

        $ratio1 = $scores[6] / $scores[3];
        $ratio2 = $scores[12] / $scores[6];

        self::assertGreaterThan($ratio1, $ratio2);
    }

    private function computeScore(HierarchyNode $root, float $alpha): float
    {
        $score = 0.0;
        foreach ($root->allNodes() as $node) {
            $score += $alpha * $node->branchingFactor() + (1 - $alpha) * $node->depth;
        }
        return $score;
    }
}
