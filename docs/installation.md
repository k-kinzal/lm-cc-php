# Installation

## Install LM-CC

```bash
# As a project dependency
composer require k-kinzal/lm-cc-php

# Or globally
composer global require k-kinzal/lm-cc-php
```

## LLM Backend Setup

LM-CC requires an LLM backend to compute token entropies.

### Option A: Ollama (Recommended)

Free, local, no API key needed.

```bash
# Install Ollama
curl -fsSL https://ollama.com/install.sh | sh

# Pull the default model
ollama pull llama3

# Start the server (default: http://localhost:11434)
ollama serve
```

Verify it's running:

```bash
curl http://localhost:11434/api/tags
```

### Option B: OpenAI API

```bash
# Set your API key
export LMCC_API_KEY=sk-...
```

Configure in `lm-cc.yaml`:

```yaml
backend: openai
model: gpt-3.5-turbo-instruct
base_url: "https://api.openai.com"
```

Or pass via CLI:

```bash
vendor/bin/lm-cc analyze src/ --backend openai --model gpt-3.5-turbo-instruct --api-key sk-...
```

> **Note:** The OpenAI backend uses the legacy `/v1/completions` API with `echo` and `logprobs`. Use instruct models (e.g., `gpt-3.5-turbo-instruct`) — chat models like `gpt-4` are not supported.
