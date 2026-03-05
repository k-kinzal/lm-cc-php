<?php

declare(strict_types=1);

namespace Lmcc\Hierarchy;

final class SemanticUnit
{
    public function __construct(
        public readonly int $index,
        public readonly string $content,
        public readonly int $indentLevel,
        public readonly int $startOffset,
        public readonly int $endOffset,
        public readonly int $startLine,
        public readonly int $endLine,
    ) {
    }
}
