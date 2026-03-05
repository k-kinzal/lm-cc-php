<?php

declare(strict_types=1);

namespace Lmcc\Llm;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OllamaClient implements LlmClientInterface
{
    private const ECHO_SYSTEM_PROMPT = 'Repeat the following text exactly as-is. Output nothing else.';
    private const MAX_CHUNK_CHARS = 2000;
    private const MAX_RETRIES = 3;
    private const MIN_COVERAGE = 0.30;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        private readonly string $model,
    ) {
    }

    public function getTokenEntropies(string $code): array
    {
        if ($code === '') {
            return [];
        }

        if (strlen($code) <= self::MAX_CHUNK_CHARS) {
            return $this->processChunkWithRetry($code, 0);
        }

        return $this->processInChunks($code);
    }

    /**
     * @return TokenEntropy[]
     */
    private function processInChunks(string $code): array
    {
        $chunks = $this->splitIntoChunks($code);
        $allTokens = [];
        $globalOffset = 0;

        foreach ($chunks as $chunk) {
            $chunkTokens = $this->processChunkWithRetry($chunk, $globalOffset);
            foreach ($chunkTokens as $token) {
                $allTokens[] = $token;
            }
            $globalOffset += strlen($chunk);
        }

        return $allTokens;
    }

    /**
     * @return string[]
     */
    private function splitIntoChunks(string $code): array
    {
        $lines = explode("\n", $code);
        $lineCount = count($lines);
        $rawChunks = [];
        $currentChunk = '';

        // Step 1: Simple split at line boundaries when exceeding MAX_CHUNK_CHARS
        foreach ($lines as $i => $line) {
            $lineWithNewline = $line . ($i < $lineCount - 1 ? "\n" : '');
            if ($currentChunk !== '' && strlen($currentChunk) + strlen($lineWithNewline) > self::MAX_CHUNK_CHARS) {
                $rawChunks[] = $currentChunk;
                $currentChunk = '';
            }
            $currentChunk .= $lineWithNewline;
        }
        if ($currentChunk !== '') {
            $rawChunks[] = $currentChunk;
        }

        if (count($rawChunks) <= 1) {
            return $rawChunks;
        }

        // Step 2: Fix chunk boundaries — move leading closing-delimiter
        // and empty lines from each chunk to the previous one.
        // Models struggle with chunks starting mid-expression (e.g., "),", "};").
        $chunks = [$rawChunks[0]];
        for ($i = 1; $i < count($rawChunks); $i++) {
            $chunk = $rawChunks[$i];
            $chunkLines = explode("\n", $chunk);
            $moveCount = 0;
            foreach ($chunkLines as $cl) {
                $trimmed = ltrim($cl);
                if ($trimmed === '' || $trimmed[0] === '}' || $trimmed[0] === ')' || $trimmed[0] === ']') {
                    $moveCount++;
                } else {
                    break;
                }
            }

            if ($moveCount > 0 && $moveCount < count($chunkLines)) {
                $movedLines = implode("\n", array_slice($chunkLines, 0, $moveCount)) . "\n";
                $remaining = implode("\n", array_slice($chunkLines, $moveCount));
                $chunks[count($chunks) - 1] .= $movedLines;
                $chunks[] = $remaining;
            } else {
                $chunks[] = $chunk;
            }
        }

        // Step 3: Merge small trailing chunk (< 500 chars lacks context for faithful reproduction)
        if (count($chunks) > 1 && strlen($chunks[count($chunks) - 1]) < 500) {
            $last = array_pop($chunks);
            $chunks[count($chunks) - 1] .= $last;
        }

        return $chunks;
    }

    /**
     * @return TokenEntropy[]
     */
    private function processChunkWithRetry(string $code, int $baseOffset): array
    {
        // Strip common leading indentation so the model can reproduce code faithfully.
        // Models tend to strip indentation when echoing code fragments.
        [$dedented, $indentMap] = $this->dedentCode($code);

        $lastException = null;

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $tokens = $this->callApi($dedented, 0);

                // Re-map offsets to account for removed indentation
                return $this->remapOffsets($tokens, $indentMap, $baseOffset);
            } catch (\RuntimeException $e) {
                $lastException = $e;
                if (!str_contains($e->getMessage(), 'reproduced') && !str_contains($e->getMessage(), 'aligned')) {
                    throw $e;
                }
            }
        }

        throw $lastException;
    }

    /**
     * Remove leading indentation from code based on the first non-empty line,
     * so the LLM can reproduce it faithfully. Models tend to normalize indentation
     * to start from column 0 when echoing code.
     *
     * @return array{string, array<int, int>} [dedented code, offset map from dedented pos to original pos]
     */
    private function dedentCode(string $code): array
    {
        $lines = explode("\n", $code);

        // Use the first non-empty line's indentation as the base to strip.
        // This matches how the model normalizes: it starts the output at column 0.
        $minIndent = 0;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $stripped = ltrim($line);
            $minIndent = strlen($line) - strlen($stripped);
            break;
        }
        if ($minIndent === 0) {
            // No common indentation to strip
            $identityMap = [];
            $pos = 0;
            for ($i = 0; $i < strlen($code); $i++) {
                $identityMap[$i] = $i;
            }
            return [$code, $identityMap];
        }

        // Build dedented code and offset mapping
        $dedented = '';
        $offsetMap = []; // dedented position → original position
        $originalPos = 0;
        $dedentedPos = 0;

        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                // Empty line: keep as-is
                $dedented .= $line;
                for ($j = 0; $j < strlen($line); $j++) {
                    $offsetMap[$dedentedPos + $j] = $originalPos + $j;
                }
                $dedentedPos += strlen($line);
            } else {
                // Strip up to minIndent chars from start
                $lineIndent = strlen($line) - strlen(ltrim($line));
                $strip = min($minIndent, $lineIndent);
                $stripped = substr($line, $strip);
                $dedented .= $stripped;
                for ($j = 0; $j < strlen($stripped); $j++) {
                    $offsetMap[$dedentedPos + $j] = $originalPos + $strip + $j;
                }
                $dedentedPos += strlen($stripped);
            }
            $originalPos += strlen($line);

            // Add newline between lines (except last)
            if ($i < count($lines) - 1) {
                $offsetMap[$dedentedPos] = $originalPos;
                $dedented .= "\n";
                $dedentedPos++;
                $originalPos++;
            }
        }

        return [$dedented, $offsetMap];
    }

    /**
     * Remap token offsets from dedented positions back to original positions.
     *
     * @param TokenEntropy[] $tokens
     * @param array<int, int> $offsetMap
     * @return TokenEntropy[]
     */
    private function remapOffsets(array $tokens, array $offsetMap, int $baseOffset): array
    {
        $result = [];
        foreach ($tokens as $te) {
            $originalOffset = $offsetMap[$te->offset] ?? $te->offset;
            $result[] = new TokenEntropy(
                token: $te->token,
                entropy: $te->entropy,
                offset: $baseOffset + $originalOffset,
            );
        }
        return $result;
    }

    /**
     * @return TokenEntropy[]
     */
    private function callApi(string $code, int $baseOffset): array
    {
        try {
            $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/') . '/v1/chat/completions', [
                'timeout' => 600,
                'json' => [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => self::ECHO_SYSTEM_PROMPT],
                        ['role' => 'user', 'content' => $code],
                    ],
                    'temperature' => 0,
                    'logprobs' => true,
                    'top_logprobs' => 5,
                    'max_tokens' => $this->estimateMaxTokens($code),
                    'options' => [
                        'num_ctx' => $this->estimateContextWindow($code),
                    ],
                ],
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 400) {
                throw new \RuntimeException(sprintf('Ollama API returned HTTP %d', $statusCode));
            }

            $body = $response->getContent();
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Ollama API request failed: ' . $e->getMessage(), 0, $e);
        }

        try {
            $data = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Ollama API returned invalid JSON: ' . $e->getMessage(), 0, $e);
        }

        $choice = $data['choices'][0] ?? null;
        if ($choice === null) {
            throw new \RuntimeException('Ollama API response contains no choices');
        }

        $logprobsContent = $choice['logprobs']['content'] ?? null;
        if ($logprobsContent === null || !is_array($logprobsContent) || $logprobsContent === []) {
            throw new \RuntimeException(
                'Ollama API response contains no per-token logprobs. '
                . 'Ensure logprobs are enabled and the model supports them.'
            );
        }

        return $this->alignTokens($logprobsContent, $code, $baseOffset);
    }

    /**
     * Align generated tokens to the original code.
     * Uses character-level alignment between concatenated model output and code,
     * then maps back to individual tokens for entropy values.
     *
     * @param array<int, array<string, mixed>> $logprobsContent
     * @return TokenEntropy[]
     */
    private function alignTokens(array $logprobsContent, string $originalCode, int $baseOffset): array
    {
        $code = rtrim($originalCode);
        $codeLen = strlen($code);
        if ($codeLen === 0) {
            return [];
        }

        // Concatenate all model output tokens into one string
        $fullOutput = '';
        $tokenBoundaries = []; // [outputStart, outputEnd, entryIndex]
        foreach ($logprobsContent as $idx => $entry) {
            $tokenStr = (string) ($entry['token'] ?? '');
            if ($tokenStr === '') {
                continue;
            }
            $start = strlen($fullOutput);
            $fullOutput .= $tokenStr;
            $tokenBoundaries[] = [$start, strlen($fullOutput), $idx];
        }

        // Find where the code starts in the model output
        $alignment = $this->findCodeAlignment($fullOutput, $code);
        if ($alignment === null) {
            throw new \RuntimeException('No tokens could be aligned to the original code.');
        }

        [$outputStart, $codeStart] = $alignment;

        // Build character-level mapping: for each code position, find the
        // corresponding output position. Allow skipping small gaps in either
        // the code or output to tolerate minor model differences.
        $codeToOutput = $this->buildCharMapping($code, $codeStart, $fullOutput, $outputStart);

        // Map each model token to its corresponding code region
        $tokens = [];
        foreach ($tokenBoundaries as [$tStart, $tEnd, $entryIdx]) {
            // Find what code region this token covers
            $codeRegionStart = null;
            $codeRegionEnd = null;

            foreach ($codeToOutput as $cPos => $oPos) {
                if ($oPos >= $tStart && $oPos < $tEnd) {
                    if ($codeRegionStart === null) {
                        $codeRegionStart = $cPos;
                    }
                    $codeRegionEnd = $cPos + 1;
                }
            }

            if ($codeRegionStart === null) {
                continue;
            }

            $tokenText = substr($code, $codeRegionStart, $codeRegionEnd - $codeRegionStart);
            if ($tokenText === '') {
                continue;
            }

            $entropy = $this->computeEntropyFromTopLogprobs(
                $logprobsContent[$entryIdx]['top_logprobs'] ?? []
            );
            $tokens[] = new TokenEntropy(
                token: $tokenText,
                entropy: $entropy,
                offset: $baseOffset + $codeRegionStart,
            );
        }

        if ($tokens === []) {
            throw new \RuntimeException('No tokens could be aligned to the original code.');
        }

        // Coverage = how much of the code was mapped
        $mappedChars = count($codeToOutput);
        $totalChars = $codeLen - $codeStart;
        $coverage = $totalChars > 0 ? $mappedChars / $totalChars : 0;

        if ($coverage < self::MIN_COVERAGE) {
            throw new \RuntimeException(
                sprintf(
                    'Model only reproduced %.0f%% of the code (%d/%d chars). '
                    . 'Try a different model or reduce file size.',
                    $coverage * 100,
                    $mappedChars,
                    $totalChars
                )
            );
        }

        return $tokens;
    }

    /**
     * Build a character-level mapping from code positions to output positions.
     * Uses line-level alignment to handle indentation differences, then maps
     * characters within each matched line.
     *
     * @return array<int, int> code position => output position
     */
    private function buildCharMapping(string $code, int $codeStart, string $output, int $outputStart): array
    {
        // Split into lines for line-level alignment
        $codeLines = explode("\n", substr($code, $codeStart));
        $outputLines = explode("\n", substr($output, $outputStart));

        $mapping = [];
        $cLineIdx = 0;
        $oLineIdx = 0;
        $codeOffset = $codeStart;
        $outputOffset = $outputStart;

        // Pre-compute trimmed versions for matching
        $codeTrimmed = array_map('ltrim', $codeLines);
        $outputTrimmed = array_map('ltrim', $outputLines);

        while ($cLineIdx < count($codeLines) && $oLineIdx < count($outputLines)) {
            $cLine = $codeLines[$cLineIdx];
            $oLine = $outputLines[$oLineIdx];
            $cTrimmed = $codeTrimmed[$cLineIdx];
            $oTrimmed = $outputTrimmed[$oLineIdx];

            // Lines match after trimming leading whitespace
            if ($cTrimmed === $oTrimmed && $cTrimmed !== '') {
                // Map the non-whitespace content character by character
                $cLeadWs = strlen($cLine) - strlen($cTrimmed);
                $oLeadWs = strlen($oLine) - strlen($oTrimmed);

                for ($j = 0; $j < strlen($cTrimmed); $j++) {
                    $mapping[$codeOffset + $cLeadWs + $j] = $outputOffset + $oLeadWs + $j;
                }

                $codeOffset += strlen($cLine) + 1; // +1 for newline
                $outputOffset += strlen($oLine) + 1;
                $cLineIdx++;
                $oLineIdx++;
                continue;
            }

            // Both empty lines
            if ($cTrimmed === '' && $oTrimmed === '') {
                $codeOffset += strlen($cLine) + 1;
                $outputOffset += strlen($oLine) + 1;
                $cLineIdx++;
                $oLineIdx++;
                continue;
            }

            // Mismatch: try to find the code line in upcoming output lines
            $found = false;

            // Strategy 1: model dropped code lines → skip ahead in code
            if ($cTrimmed !== '' && $oTrimmed !== '') {
                for ($ahead = 1; $ahead <= 10 && $cLineIdx + $ahead < count($codeLines); $ahead++) {
                    if ($codeTrimmed[$cLineIdx + $ahead] === $oTrimmed) {
                        // Verify next line also matches to avoid false positives on common lines like "}"
                        $verified = ($ahead <= 2); // Short skips are usually safe
                        if (!$verified && $oLineIdx + 1 < count($outputLines) && $cLineIdx + $ahead + 1 < count($codeLines)) {
                            $verified = $codeTrimmed[$cLineIdx + $ahead + 1] === $outputTrimmed[$oLineIdx + 1]
                                || $outputTrimmed[$oLineIdx + 1] === '';
                        }
                        if ($verified) {
                            for ($s = 0; $s < $ahead; $s++) {
                                $codeOffset += strlen($codeLines[$cLineIdx]) + 1;
                                $cLineIdx++;
                            }
                            $found = true;
                            break;
                        }
                    }
                }
            }

            // Strategy 2: model added extra lines → skip ahead in output
            if (!$found && $cTrimmed !== '' && $oTrimmed !== '') {
                for ($ahead = 1; $ahead <= 10 && $oLineIdx + $ahead < count($outputLines); $ahead++) {
                    if ($outputTrimmed[$oLineIdx + $ahead] === $cTrimmed) {
                        $verified = ($ahead <= 2);
                        if (!$verified && $cLineIdx + 1 < count($codeLines) && $oLineIdx + $ahead + 1 < count($outputLines)) {
                            $verified = $outputTrimmed[$oLineIdx + $ahead + 1] === $codeTrimmed[$cLineIdx + 1]
                                || $codeTrimmed[$cLineIdx + 1] === '';
                        }
                        if ($verified) {
                            for ($s = 0; $s < $ahead; $s++) {
                                $outputOffset += strlen($outputLines[$oLineIdx]) + 1;
                                $oLineIdx++;
                            }
                            $found = true;
                            break;
                        }
                    }
                }
            }

            // Strategy 3: one side is empty, skip it
            if (!$found) {
                if ($cTrimmed === '' && $oTrimmed !== '') {
                    $codeOffset += strlen($cLine) + 1;
                    $cLineIdx++;
                    $found = true;
                } elseif ($oTrimmed === '' && $cTrimmed !== '') {
                    $outputOffset += strlen($oLine) + 1;
                    $oLineIdx++;
                    $found = true;
                }
            }

            // Strategy 4: skip code lines to find output line match
            if (!$found && $oTrimmed !== '') {
                for ($ahead = 1; $ahead <= 5 && $cLineIdx + $ahead < count($codeLines); $ahead++) {
                    if ($codeTrimmed[$cLineIdx + $ahead] === $oTrimmed) {
                        for ($s = 0; $s < $ahead; $s++) {
                            $codeOffset += strlen($codeLines[$cLineIdx]) + 1;
                            $cLineIdx++;
                        }
                        $found = true;
                        break;
                    }
                }
            }

            if (!$found) {
                // Strategy 5: partial line matching (common prefix)
                // When lines share content but one has extra at the end
                $cTrim = ltrim($cLine);
                $oTrim = ltrim($oLine);
                $minLen = min(strlen($cTrim), strlen($oTrim));
                if ($minLen > 3) {
                    $prefixLen = 0;
                    while ($prefixLen < $minLen && $cTrim[$prefixLen] === $oTrim[$prefixLen]) {
                        $prefixLen++;
                    }
                    if ($prefixLen >= $minLen * 0.5 && $prefixLen > 3) {
                        $cLeadWs = strlen($cLine) - strlen($cTrim);
                        $oLeadWs = strlen($oLine) - strlen($oTrim);
                        for ($j = 0; $j < $prefixLen; $j++) {
                            $mapping[$codeOffset + $cLeadWs + $j] = $outputOffset + $oLeadWs + $j;
                        }
                    }
                }
                $codeOffset += strlen($cLine) + 1;
                $outputOffset += strlen($oLine) + 1;
                $cLineIdx++;
                $oLineIdx++;
            }
        }

        return $mapping;
    }

    /**
     * Find where the code begins in the model output by searching for
     * distinctive anchor strings. Tries multiple anchor candidates to
     * handle cases where the model changes the first character(s).
     *
     * @return array{int, int}|null [outputStart, codeStart] or null if not found
     */
    private function findCodeAlignment(string $output, string $code): ?array
    {
        $codeLen = strlen($code);

        // Collect candidate anchor positions: every non-whitespace position
        // that starts a "distinctive" sequence (letters, $, keywords)
        $candidates = [];
        $inWord = false;
        for ($i = 0; $i < $codeLen; $i++) {
            $ch = $code[$i];
            $isWs = ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r");
            if (!$isWs && !$inWord) {
                $candidates[] = $i;
                $inWord = true;
            } elseif ($isWs) {
                $inWord = false;
            }
        }

        if ($candidates === []) {
            return null;
        }

        // Try anchors starting from each candidate position, preferring earlier ones
        foreach ($candidates as $anchorStart) {
            $anchorLen = min(30, $codeLen - $anchorStart);
            if ($anchorLen < 3) {
                continue;
            }
            $anchor = substr($code, $anchorStart, $anchorLen);

            $anchorPos = strpos($output, $anchor);
            if ($anchorPos === false) {
                // Try shorter anchor
                $shortLen = min(10, $anchorLen);
                $anchor = substr($code, $anchorStart, $shortLen);
                $anchorPos = strpos($output, $anchor);
                if ($anchorPos === false) {
                    continue;
                }
            }

            // Found anchor. Try to include any code content before it.
            if ($anchorStart > 0 && $anchorPos >= $anchorStart) {
                $prefix = substr($code, 0, $anchorStart);
                $candidateStart = $anchorPos - $anchorStart;
                if (substr($output, $candidateStart, $anchorStart) === $prefix) {
                    return [$candidateStart, 0];
                }
            }

            // Align from this anchor position, skipping code before the anchor
            return [$anchorPos, $anchorStart];
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $topLogprobs
     */
    private function computeEntropyFromTopLogprobs(array $topLogprobs): float
    {
        if ($topLogprobs === []) {
            return 0.0;
        }

        $entropy = 0.0;
        foreach ($topLogprobs as $entry) {
            $lp = $entry['logprob'] ?? null;
            if ($lp === null || !is_numeric($lp)) {
                continue;
            }
            $lp = (float) $lp;
            $p = exp($lp);
            if ($p > 0) {
                $entropy -= $p * $lp / log(2);
            }
        }

        return $entropy;
    }

    private function estimateMaxTokens(string $code): int
    {
        return max((int) ceil(strlen($code) / 2.0 * 2.0), 256);
    }

    private function estimateContextWindow(string $code): int
    {
        $codeTokens = (int) ceil(strlen($code) / 3.0);
        $needed = ($codeTokens * 2) + 200;

        $ctx = 4096;
        while ($ctx < $needed) {
            $ctx *= 2;
        }

        return min($ctx, 131072);
    }
}
