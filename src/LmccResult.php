<?php

declare(strict_types=1);

namespace Lmcc;

final class LmccResult
{
    public function __construct(
        public readonly string $filePath,
        public readonly float $score,
        public readonly float $totalBranch,
        public readonly float $totalCompLevel,
        public readonly int $maxCompLevel,
        public readonly float $avgCompLevel,
        public readonly int $maxBranch,
        public readonly float $avgBranch,
        public readonly int $nodeCount,
        public readonly int $tokenCount,
        public readonly float $tau,
        public readonly int $boundaryCount,
        public readonly int $unitCount,
    ) {
    }

    public function exceedsThreshold(float $threshold): bool
    {
        return $this->score > $threshold;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->filePath,
            'score' => round($this->score, 2),
            'totalBranch' => round($this->totalBranch, 2),
            'totalCompLevel' => round($this->totalCompLevel, 2),
            'maxCompLevel' => $this->maxCompLevel,
            'avgCompLevel' => round($this->avgCompLevel, 2),
            'maxBranch' => $this->maxBranch,
            'avgBranch' => round($this->avgBranch, 2),
            'nodeCount' => $this->nodeCount,
            'tokenCount' => $this->tokenCount,
            'tau' => round($this->tau, 4),
            'boundaryCount' => $this->boundaryCount,
            'unitCount' => $this->unitCount,
        ];
    }

    public static function empty(string $filePath): self
    {
        return new self(
            filePath: $filePath,
            score: 0.0,
            totalBranch: 0.0,
            totalCompLevel: 0.0,
            maxCompLevel: 0,
            avgCompLevel: 0.0,
            maxBranch: 0,
            avgBranch: 0.0,
            nodeCount: 0,
            tokenCount: 0,
            tau: 0.0,
            boundaryCount: 0,
            unitCount: 0,
        );
    }
}
