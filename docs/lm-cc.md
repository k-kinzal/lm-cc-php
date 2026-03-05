# What is LM-CC?

LM-CC (Language Model Code Complexity) is a code complexity metric designed to predict how difficult code is for large language models to process.

## The Problem

Traditional complexity metrics (cyclomatic complexity, Halstead metrics) show no consistent correlation with LLM performance on code tasks. Code that humans find complex isn't necessarily what LLMs struggle with.

## The Formula

```
LM-CC(P) = Σ_{v ∈ V} [α · b(v) + (1 - α) · d(v)]
```

Where:
- **α = 0.8** — weighting parameter (from ablation study)
- **b(v)** — branching factor (number of children) of node v
- **d(v)** — compositional level (depth) of node v
- **V** — all nodes in the hierarchy tree

Equivalent to: `α · TotalBranch + (1 - α) · TotalCompLevel`

## Pipeline

1. **Preprocess** — Strip comments from source code
2. **Tokenize + Entropy** — Send code to LLM, get per-token entropy values
3. **Threshold** — Compute τ as the 67th percentile of entropies
4. **Boundaries** — Mark positions where entropy > τ OR token is a syntactic delimiter
5. **Decompose** — Split code into semantic units at boundaries
6. **Hierarchy** — Build tree structure using indentation levels
7. **Score** — Sum α·b(v) + (1-α)·d(v) over all nodes

## Key Property (Proposition B.1)

LM-CC separates flat from nested code — unlike cyclomatic complexity:

| Structure | Cyclomatic Complexity | LM-CC |
|-----------|----------------------|-------|
| n sequential `if`s | n + 1 | Θ(n) |
| n nested `if`s | n + 1 | Θ(n²) |

Same CC, vastly different LM-CC — matching real LLM performance differences.

## Correlation

LM-CC correlates at **r = -0.92 to -0.97** with LLM task success. Lowering LM-CC scores improves:
- Code repair: +20.9%
- Code translation: +10.2%

## Reference

Mingjie Liu, Zichao Li, Chengyu Huang, Yihong Dong, Yongqiang Chen, Shuijing He, Bo Zheng, and Ge Li. *LM-CC: Language Model Code Complexity — A New Metric from the LLM's Perspective.* [arXiv:2602.07882](https://arxiv.org/abs/2602.07882), 2025.
