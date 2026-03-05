<?php

declare(strict_types=1);

namespace Lmcc;

use Symfony\Component\Yaml\Yaml;

final class Config
{
    /**
     * @param string[] $exclude
     */
    public function __construct(
        public readonly string $backend = 'ollama',
        public readonly string $model = 'llama3',
        public readonly string $baseUrl = 'http://localhost:11434',
        public readonly string $apiKey = '',
        public readonly float $alpha = 0.8,
        public readonly ?float $tauOverride = null,
        public readonly float $percentile = 67.0,
        public readonly ?float $threshold = null,
        public readonly string $format = 'text',
        public readonly array $exclude = ['vendor/*', 'tests/*'],
    ) {
    }

    /**
     * @param array<string, mixed> $cliOverrides
     */
    public static function load(?string $configFile, array $cliOverrides = []): self
    {
        $values = [];

        // 1. Try config file
        $file = $configFile;
        if ($file === null) {
            foreach (['lm-cc.yaml', '.lm-cc.yaml'] as $candidate) {
                if (file_exists($candidate)) {
                    $file = $candidate;
                    break;
                }
            }
        }

        if ($file !== null && file_exists($file)) {
            $parsed = Yaml::parseFile($file);
            if (is_array($parsed)) {
                $values = $parsed;
            }
        }

        // 2. Environment variables (override config file)
        $envMap = [
            'LMCC_BACKEND' => 'backend',
            'LMCC_MODEL' => 'model',
            'LMCC_API_KEY' => 'api_key',
            'LMCC_BASE_URL' => 'base_url',
        ];
        foreach ($envMap as $env => $key) {
            $val = getenv($env);
            if ($val !== false && $val !== '') {
                $values[$key] = $val;
            }
        }

        // 3. CLI overrides (highest precedence)
        foreach ($cliOverrides as $key => $val) {
            if ($val !== null) {
                $values[$key] = $val;
            }
        }

        return new self(
            backend: (string) ($values['backend'] ?? 'ollama'),
            model: (string) ($values['model'] ?? 'llama3'),
            baseUrl: (string) ($values['base_url'] ?? 'http://localhost:11434'),
            apiKey: (string) ($values['api_key'] ?? ''),
            alpha: (float) ($values['alpha'] ?? 0.8),
            tauOverride: isset($values['tau']) ? (float) $values['tau'] : null,
            percentile: (float) ($values['percentile'] ?? 67.0),
            threshold: isset($values['threshold']) ? (float) $values['threshold'] : null,
            format: (string) ($values['format'] ?? 'text'),
            exclude: isset($values['exclude']) ? (array) $values['exclude'] : ['vendor/*', 'tests/*'],
        );
    }
}
