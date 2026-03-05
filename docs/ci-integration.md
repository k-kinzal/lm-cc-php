# CI Integration

## GitHub Actions

```yaml
name: LM-CC
on: [push, pull_request]

jobs:
  lm-cc:
    runs-on: ubuntu-latest
    services:
      ollama:
        image: ollama/ollama:latest
        ports:
          - 11434:11434
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
      - run: composer install --no-interaction
      - run: |
          curl -s http://localhost:11434/api/pull -d '{"name":"llama3"}'
      - run: vendor/bin/lm-cc analyze src/ --threshold 50.0
```

## GitLab CI

```yaml
lm-cc:
  image: php:8.2
  services:
    - name: ollama/ollama:latest
      alias: ollama
  variables:
    LMCC_BASE_URL: "http://ollama:11434"
  script:
    - composer install --no-interaction
    - vendor/bin/lm-cc analyze src/ --threshold 50.0
  allow_failure: false
```

## Generic CI

```bash
#!/bin/bash

composer install --no-interaction
vendor/bin/lm-cc analyze src/ --threshold 50.0 --format json
exit_code=$?

if [ $exit_code -eq 1 ]; then
  echo "LM-CC threshold exceeded!"
  exit 1
elif [ $exit_code -eq 2 ]; then
  echo "LM-CC analysis error!"
  exit 2
fi
```

## Using the Reusable Workflow

This project provides a reusable GitHub Actions workflow:

```yaml
jobs:
  lm-cc:
    uses: lm-cc/lm-cc/.github/workflows/lm-cc.yml@main
    with:
      threshold: '50.0'
      paths: 'src/'
    secrets:
      LMCC_API_KEY: ${{ secrets.LMCC_API_KEY }}
```
