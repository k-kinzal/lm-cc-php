# Usage

## Commands

### `lm-cc analyze` (default)

Compute LM-CC scores for PHP files.

```bash
vendor/bin/lm-cc analyze src/
vendor/bin/lm-cc analyze src/Controller/UserController.php
vendor/bin/lm-cc analyze src/ --threshold 50.0
vendor/bin/lm-cc analyze src/ --format json
```

### `lm-cc init`

Generate a default `lm-cc.yaml` config file.

```bash
vendor/bin/lm-cc init
```

## Options

| Option | Short | Type | Default | Description |
|--------|-------|------|---------|-------------|
| `paths` | — | argument | `.` | Files or directories to analyze |
| `--config` | `-c` | string | auto-detect | Path to YAML config file |
| `--format` | `-f` | string | `text` | Output format: `text` or `json` |
| `--backend` | `-b` | string | `ollama` | LLM backend: `ollama` or `openai` |
| `--model` | `-m` | string | `llama3` | LLM model name |
| `--api-key` | — | string | env `LMCC_API_KEY` | API key for OpenAI backend |
| `--base-url` | — | string | `http://localhost:11434` | LLM API base URL |
| `--alpha` | — | float | `0.8` | Weighting parameter (0.0-1.0) |
| `--tau` | — | float | auto | Fixed entropy threshold override |
| `--percentile` | — | float | `67.0` | Percentile for dynamic tau |
| `--threshold` | `-t` | float | none | Fail if any file exceeds this score |
| `--exclude` | `-e` | string[] | `vendor/*,tests/*` | Glob patterns to exclude |

## Exit Codes

| Code | Meaning |
|------|---------|
| `0` | Success — all files analyzed, no threshold exceeded |
| `1` | Threshold exceeded — at least one file above threshold |
| `2` | Runtime error — LLM unreachable, invalid config, etc. |

## Configuration File

Place `lm-cc.yaml` or `.lm-cc.yaml` in your project root. Run `lm-cc init` to generate a template.

Config precedence (highest to lowest):
1. CLI options
2. Environment variables (`LMCC_API_KEY`, `LMCC_BACKEND`, `LMCC_MODEL`, `LMCC_BASE_URL`)
3. Config file
4. Built-in defaults
