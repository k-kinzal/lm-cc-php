<?php

declare(strict_types=1);

namespace Lmcc\Llm;

interface LlmClientInterface
{
    /**
     * Send code text to LLM, get per-BPE-token entropy values.
     * The LLM tokenizes the code internally using its BPE tokenizer.
     *
     * @return TokenEntropy[] Ordered by position in the code
     */
    public function getTokenEntropies(string $code): array;
}
