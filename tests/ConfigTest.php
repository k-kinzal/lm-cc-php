<?php

declare(strict_types=1);

namespace Lmcc\Tests;

use Lmcc\Config;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    #[Test]
    public function defaults_when_no_config(): void
    {
        // Pass explicit nonexistent path to prevent auto-detection of lm-cc.yaml in cwd
        $config = Config::load('/nonexistent/no-config.yaml', []);

        self::assertSame('ollama', $config->backend);
        self::assertSame('llama3', $config->model);
        self::assertSame('http://localhost:11434', $config->baseUrl);
        self::assertSame('', $config->apiKey);
        self::assertEqualsWithDelta(0.8, $config->alpha, 0.001);
        self::assertNull($config->tauOverride);
        self::assertEqualsWithDelta(67.0, $config->percentile, 0.001);
        self::assertNull($config->threshold);
        self::assertSame('text', $config->format);
    }

    #[Test]
    public function cli_overrides_take_highest_precedence(): void
    {
        $config = Config::load(null, [
            'backend' => 'openai',
            'model' => 'gpt-4',
            'alpha' => '0.5',
            'threshold' => '42.0',
            'format' => 'json',
        ]);

        self::assertSame('openai', $config->backend);
        self::assertSame('gpt-4', $config->model);
        self::assertEqualsWithDelta(0.5, $config->alpha, 0.001);
        self::assertEqualsWithDelta(42.0, $config->threshold, 0.001);
        self::assertSame('json', $config->format);
    }

    #[Test]
    public function yaml_config_file_loaded(): void
    {
        $config = Config::load(__DIR__ . '/../config/lm-cc.dist.yaml', []);

        self::assertSame('ollama', $config->backend);
        self::assertSame('llama3', $config->model);
        self::assertEqualsWithDelta(0.8, $config->alpha, 0.001);
    }

    #[Test]
    public function cli_overrides_yaml_values(): void
    {
        $config = Config::load(__DIR__ . '/../config/lm-cc.dist.yaml', [
            'model' => 'custom-model',
            'alpha' => '0.3',
        ]);

        self::assertSame('custom-model', $config->model);
        self::assertEqualsWithDelta(0.3, $config->alpha, 0.001);
        // Non-overridden values come from YAML
        self::assertSame('ollama', $config->backend);
    }

    #[Test]
    public function tau_override_from_config(): void
    {
        $config = Config::load(null, ['tau' => '1.5']);

        self::assertEqualsWithDelta(1.5, $config->tauOverride, 0.001);
    }

    #[Test]
    public function exclude_from_overrides(): void
    {
        $config = Config::load(null, [
            'exclude' => ['custom/*', 'other/*'],
        ]);

        self::assertSame(['custom/*', 'other/*'], $config->exclude);
    }

    #[Test]
    public function nonexistent_config_file_uses_defaults(): void
    {
        $config = Config::load('/nonexistent/file.yaml', []);

        self::assertSame('ollama', $config->backend);
        self::assertSame('llama3', $config->model);
    }
}
