<?php

declare(strict_types=1);

namespace Lmcc\Hierarchy;

final readonly class SemanticUnit
{
    public function __construct(
        public int $index,
        public string $content,
        public int $indentLevel,
        public int $startOffset,
        public int $endOffset,
        public int $startLine,
        public int $endLine,
    ) {
    }
}
