<?php

declare(strict_types=1);

namespace Lmcc;

use Lmcc\Hierarchy\HierarchyBuilder;
use Lmcc\Hierarchy\HierarchyNode;
use Lmcc\Hierarchy\SemanticUnit;
use Lmcc\Llm\LlmClientInterface;
use Lmcc\Llm\TokenEntropy;

final class LmccAnalyzer
{
    private const DELIMITER_SYMBOLS = ['{', '}', ';'];

    private const DELIMITER_TOKENS = [
        T_IF, T_ELSE, T_ELSEIF, T_WHILE, T_FOR, T_FOREACH, T_DO,
        T_SWITCH, T_CASE, T_DEFAULT, T_TRY, T_CATCH, T_FINALLY,
        T_FUNCTION, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM,
        T_RETURN, T_BREAK, T_CONTINUE, T_THROW,
        T_ENDIF, T_ENDFOR, T_ENDWHILE, T_ENDFOREACH, T_ENDSWITCH,
    ];

    public function __construct(
        private readonly LlmClientInterface $llmClient,
        private readonly float $alpha = 0.8,
        private readonly ?float $tauOverride = null,
        private readonly float $percentile = 67.0,
    ) {
    }

    public function analyze(string $code, string $filePath = ''): LmccResult
    {
        if (trim($code) === '') {
            return LmccResult::empty($filePath);
        }

        $cleanCode = $this->stripComments($code);

        $tokenEntropies = $this->llmClient->getTokenEntropies($cleanCode);
        if ($tokenEntropies === []) {
            return LmccResult::empty($filePath);
        }

        $delimiterOffsets = $this->buildDelimiterOffsets($cleanCode);
        $tau = $this->computeTau($tokenEntropies);
        $boundaries = $this->detectBoundaries($tokenEntropies, $tau, $delimiterOffsets);
        $units = $this->decompose($tokenEntropies, $boundaries, $cleanCode);

        if ($units === []) {
            return LmccResult::empty($filePath);
        }

        return $this->computeScore($units, $filePath, $tau, $boundaries, $tokenEntropies);
    }

    public function analyzeFile(string $path): LmccResult
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException("Cannot read file: {$path}");
        }

        $code = file_get_contents($path);
        if ($code === false) {
            throw new \RuntimeException("Cannot read file: {$path}");
        }

        return $this->analyze($code, $path);
    }

    /**
     * Remove T_COMMENT and T_DOC_COMMENT, preserving line count via newlines.
     */
    private function stripComments(string $source): string
    {
        $tokens = token_get_all($source);
        $result = '';

        foreach ($tokens as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    $newlineCount = substr_count($token[1], "\n");
                    $result .= str_repeat("\n", $newlineCount);
                } else {
                    $result .= $token[1];
                }
            } else {
                $result .= $token;
            }
        }

        // Strip trailing whitespace from each line (docblock indentation remnants)
        $result = preg_replace('/[ \t]+$/m', '', $result) ?? $result;

        // Collapse 3+ consecutive newlines into 2, so LLMs can reproduce the code faithfully.
        $result = preg_replace('/\n{3,}/', "\n\n", $result) ?? $result;

        return $result;
    }

    /**
     * Build array of [startOffset, endOffset] for syntactic delimiter tokens.
     *
     * @return array<int, array{int, int}>
     */
    private function buildDelimiterOffsets(string $cleanCode): array
    {
        $tokens = token_get_all($cleanCode);
        $offsets = [];
        $pos = 0;

        foreach ($tokens as $token) {
            if (is_array($token)) {
                $text = $token[1];
                $len = strlen($text);
                if (in_array($token[0], self::DELIMITER_TOKENS, true)) {
                    $offsets[] = [$pos, $pos + $len];
                }
                $pos += $len;
            } else {
                $len = strlen($token);
                if (in_array($token, self::DELIMITER_SYMBOLS, true)) {
                    $offsets[] = [$pos, $pos + $len];
                }
                $pos += $len;
            }
        }

        return $offsets;
    }

    /**
     * Check if a BPE token overlaps with any syntactic delimiter range.
     *
     * @param array<int, array{int, int}> $delimiterOffsets
     */
    private function isSyntacticDelimiter(TokenEntropy $te, array $delimiterOffsets): bool
    {
        $tokenStart = $te->offset;
        $tokenEnd = $te->offset + strlen($te->token);

        foreach ($delimiterOffsets as [$delimStart, $delimEnd]) {
            if ($tokenStart < $delimEnd && $tokenEnd > $delimStart) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compute threshold tau: either the override or the given percentile of entropy values.
     *
     * @param TokenEntropy[] $tokenEntropies
     */
    private function computeTau(array $tokenEntropies): float
    {
        if ($this->tauOverride !== null) {
            return $this->tauOverride;
        }

        $values = array_map(fn(TokenEntropy $te) => $te->entropy, $tokenEntropies);
        sort($values);

        $count = count($values);
        $index = (int) ceil($count * $this->percentile / 100.0) - 1;
        $index = max(0, min($index, $count - 1));

        return $values[$index];
    }

    /**
     * Return indices where entropy > tau OR token overlaps syntactic delimiter.
     *
     * @param TokenEntropy[] $tokenEntropies
     * @param array<int, array{int, int}> $delimiterOffsets
     * @return int[]
     */
    private function detectBoundaries(array $tokenEntropies, float $tau, array $delimiterOffsets): array
    {
        $boundaries = [];

        foreach ($tokenEntropies as $i => $te) {
            if ($te->entropy > $tau || $this->isSyntacticDelimiter($te, $delimiterOffsets)) {
                $boundaries[] = $i;
            }
        }

        return $boundaries;
    }

    /**
     * Split token stream at boundary positions into SemanticUnits.
     *
     * @param TokenEntropy[] $tokenEntropies
     * @param int[] $boundaries
     * @return SemanticUnit[]
     */
    private function decompose(array $tokenEntropies, array $boundaries, string $code): array
    {
        if ($tokenEntropies === []) {
            return [];
        }

        $boundarySet = array_flip($boundaries);
        $units = [];
        $unitIndex = 0;
        $segmentTokens = [];
        $count = count($tokenEntropies);

        for ($i = 0; $i < $count; $i++) {
            if (isset($boundarySet[$i]) && $segmentTokens !== []) {
                $units[] = $this->buildUnit($segmentTokens, $unitIndex, $code);
                $unitIndex++;
                $segmentTokens = [];
            }
            $segmentTokens[] = $tokenEntropies[$i];
        }

        if ($segmentTokens !== []) {
            $units[] = $this->buildUnit($segmentTokens, $unitIndex, $code);
        }

        return $units;
    }

    /**
     * @param TokenEntropy[] $segmentTokens
     */
    private function buildUnit(array $segmentTokens, int $index, string $code): SemanticUnit
    {
        $content = implode('', array_map(fn(TokenEntropy $te) => $te->token, $segmentTokens));
        $firstToken = $segmentTokens[0];
        $lastToken = $segmentTokens[count($segmentTokens) - 1];

        $startOffset = $firstToken->offset;
        $endOffset = $lastToken->offset + strlen($lastToken->token);

        $startLine = $this->getLineNumber($code, $startOffset);
        $endLine = $this->getLineNumber($code, max(0, $endOffset - 1));

        $indentLevel = $this->getIndentLevel($code, $startOffset);

        return new SemanticUnit(
            index: $index,
            content: $content,
            indentLevel: $indentLevel,
            startOffset: $startOffset,
            endOffset: $endOffset,
            startLine: $startLine,
            endLine: $endLine,
        );
    }

    private function getLineNumber(string $code, int $offset): int
    {
        $offset = min($offset, strlen($code));
        return substr_count($code, "\n", 0, $offset) + 1;
    }

    /**
     * Find the line containing offset, count leading whitespace (tab=4 spaces).
     */
    private function getIndentLevel(string $code, int $offset): int
    {
        $lineStart = strrpos($code, "\n", $offset - strlen($code));
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;

        $spaces = 0;
        for ($i = $lineStart; $i < strlen($code); $i++) {
            $ch = $code[$i];
            if ($ch === ' ') {
                $spaces++;
            } elseif ($ch === "\t") {
                $spaces += 4;
            } else {
                break;
            }
        }

        return (int) floor($spaces / 4);
    }

    /**
     * Build hierarchy tree and compute LM-CC score.
     *
     * @param SemanticUnit[] $units
     * @param TokenEntropy[] $tokenEntropies
     */
    private function computeScore(
        array $units,
        string $filePath,
        float $tau,
        array $boundaries,
        array $tokenEntropies,
    ): LmccResult {
        $builder = new HierarchyBuilder();
        $root = $builder->build($units);
        $allNodes = $root->allNodes();

        $totalBranch = 0.0;
        $totalDepth = 0.0;
        $maxBranch = 0;
        $maxDepth = 0;
        $nodeCount = count($allNodes);

        foreach ($allNodes as $node) {
            $b = $node->branchingFactor();
            $d = $node->depth;
            $totalBranch += $b;
            $totalDepth += $d;
            $maxBranch = max($maxBranch, $b);
            $maxDepth = max($maxDepth, $d);
        }

        $avgBranch = $nodeCount > 0 ? $totalBranch / $nodeCount : 0.0;
        $avgDepth = $nodeCount > 0 ? $totalDepth / $nodeCount : 0.0;

        $score = $this->alpha * $totalBranch + (1 - $this->alpha) * $totalDepth;

        return new LmccResult(
            filePath: $filePath,
            score: $score,
            totalBranch: $totalBranch,
            totalCompLevel: $totalDepth,
            maxCompLevel: $maxDepth,
            avgCompLevel: $avgDepth,
            maxBranch: $maxBranch,
            avgBranch: $avgBranch,
            nodeCount: $nodeCount,
            tokenCount: count($tokenEntropies),
            tau: $tau,
            boundaryCount: count($boundaries),
            unitCount: count($units),
        );
    }
}
