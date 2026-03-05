<?php

declare(strict_types=1);

namespace Lmcc\Llm;

final readonly class TokenEntropy
{
    public function __construct(
        public string $token,
        public float $entropy,
        public int $offset,
    ) {
    }
}
