<?php

declare(strict_types=1);

namespace Lmcc\Llm;

final class TokenEntropy
{
    public function __construct(
        public readonly string $token,
        public readonly float $entropy,
        public readonly int $offset,
    ) {
    }
}
