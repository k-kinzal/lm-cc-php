<?php

declare(strict_types=1);

namespace Lmcc;

final readonly class LmccResult
{
    public function __construct(
        public string $filePath,
        public float $score,
        public float $totalBranch,
        public float $totalCompLevel,
        public int $maxCompLevel,
        public float $avgCompLevel,
        public int $maxBranch,
        public float $avgBranch,
        public int $nodeCount,
        public int $tokenCount,
        public float $tau,
        public int $boundaryCount,
        public int $unitCount,
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
