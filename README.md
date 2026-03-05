# LM-CC PHP

**Language Model Code Complexity** metric for PHP codebases.

LM-CC measures code complexity from the LLM's perspective. Based on [arXiv:2602.07882](https://arxiv.org/abs/2602.07882), it correlates at r=-0.92 to -0.97 with LLM task performance — lowering LM-CC improves LLM repair (+20.9%) and translation (+10.2%) success rates.

## Installation

```bash
composer require k-kinzal/lm-cc-php
```

Requires an LLM backend:

```bash
# Option A: Ollama (recommended, free, local)
ollama pull llama3
ollama serve

# Option B: OpenAI API
export LMCC_API_KEY=sk-...
```

## Quick Start

```bash
# Analyze source directory
vendor/bin/lm-cc analyze src/

# With CI threshold (exit code 1 if exceeded)
vendor/bin/lm-cc analyze src/ --threshold 50.0

# JSON output
vendor/bin/lm-cc analyze src/ --format json

# Generate config file
vendor/bin/lm-cc init
```

## Configuration

Place `lm-cc.yaml` in your project root:

```yaml
backend: ollama
model: llama3
base_url: "http://localhost:11434"
alpha: 0.8
percentile: 67.0
threshold: 50.0
format: text
exclude:
  - "vendor/*"
  - "tests/*"
```

See [docs/usage.md](docs/usage.md) for all options.

## CI Integration

```yaml
# GitHub Actions
- name: LM-CC Analysis
  run: vendor/bin/lm-cc analyze src/ --threshold 50.0
```

See [docs/ci-integration.md](docs/ci-integration.md) for full examples.

## Documentation

- [What is LM-CC?](docs/lm-cc.md) — Theory and formula
- [Installation](docs/installation.md) — Backend setup
- [Usage](docs/usage.md) — CLI reference
- [CI Integration](docs/ci-integration.md) — Pipeline setup

## Acknowledgments

This tool is an implementation of the LM-CC metric proposed in:

> Mingjie Liu, Zichao Li, Chengyu Huang, Yihong Dong, Yongqiang Chen, Shuijing He, Bo Zheng, and Ge Li. *LM-CC: Language Model Code Complexity — A New Metric from the LLM's Perspective.* [arXiv:2602.07882](https://arxiv.org/abs/2602.07882), 2025.

## License

MIT — see [LICENSE](LICENSE).
