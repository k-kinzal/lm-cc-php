<?php

declare(strict_types=1);

namespace Lmcc\Tests\Command;

use Lmcc\Command\AnalyzeCommand;
use Lmcc\Llm\LlmClientInterface;
use Lmcc\Llm\TokenEntropy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class AnalyzeCommandTest extends TestCase
{
    private function createMockClient(): LlmClientInterface
    {
        return new class () implements LlmClientInterface {
            public function getTokenEntropies(string $code): array
            {
                if ($code === '') {
                    return [];
                }

                $tokens = [];
                $len = strlen($code);
                for ($i = 0; $i < $len; $i++) {
                    $tokens[] = new TokenEntropy(
                        token: $code[$i],
                        entropy: 0.5,
                        offset: $i,
                    );
                }
                return $tokens;
            }
        };
    }

    private function createCommandTester(?LlmClientInterface $client = null): CommandTester
    {
        $application = new Application('lm-cc', '0.1.0');
        $command = new AnalyzeCommand($client ?? $this->createMockClient());
        $application->add($command);

        return new CommandTester($application->find('analyze'));
    }

    #[Test]
    public function text_output_contains_table_headers(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute([
            'paths' => [__DIR__ . '/../Fixtures/simple_function.php'],
        ]);

        $output = $tester->getDisplay();
        self::assertStringContainsString('LM-CC Analysis Results', $output);
        self::assertStringContainsString('File', $output);
        self::assertStringContainsString('LM-CC', $output);
        self::assertStringContainsString('Tokens', $output);
        self::assertStringContainsString('simple_function.php', $output);
        self::assertSame(0, $tester->getStatusCode());
    }

    #[Test]
    public function json_output_has_correct_structure(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute([
            'paths' => [__DIR__ . '/../Fixtures/simple_function.php'],
            '--format' => 'json',
        ]);

        $output = $tester->getDisplay();
        $data = json_decode($output, true);

        self::assertNotNull($data);
        self::assertArrayHasKey('version', $data);
        self::assertArrayHasKey('config', $data);
        self::assertArrayHasKey('summary', $data);
        self::assertArrayHasKey('files', $data);
        self::assertSame(1, $data['summary']['fileCount']);
        self::assertSame(0, $tester->getStatusCode());
    }

    #[Test]
    public function threshold_pass_returns_exit_0(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute([
            'paths' => [__DIR__ . '/../Fixtures/simple_function.php'],
            '--threshold' => '99999',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('OK', $tester->getDisplay());
    }

    #[Test]
    public function threshold_fail_returns_exit_1(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute([
            'paths' => [__DIR__ . '/../Fixtures/simple_function.php'],
            '--threshold' => '0.001',
        ]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('FAIL', $tester->getDisplay());
    }

    #[Test]
    public function nonexistent_path_returns_exit_2(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute([
            'paths' => ['/nonexistent/path/to/file.php'],
        ]);

        self::assertSame(2, $tester->getStatusCode());
    }

    #[Test]
    public function analyzes_directory_recursively(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute([
            'paths' => [__DIR__ . '/../Fixtures'],
            '--format' => 'json',
        ]);

        $data = json_decode($tester->getDisplay(), true);

        self::assertNotNull($data);
        self::assertSame(3, $data['summary']['fileCount']);
        self::assertSame(0, $tester->getStatusCode());
    }

    #[Test]
    public function json_threshold_fail_shows_failed_status(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute([
            'paths' => [__DIR__ . '/../Fixtures/simple_function.php'],
            '--format' => 'json',
            '--threshold' => '0.001',
        ]);

        self::assertSame(1, $tester->getStatusCode());
        $data = json_decode($tester->getDisplay(), true);

        self::assertNotNull($data);
        self::assertFalse($data['summary']['passed']);
        self::assertGreaterThan(0, $data['summary']['failedFiles']);
        self::assertTrue($data['files'][0]['exceedsThreshold']);
    }

    #[Test]
    public function json_without_threshold_has_no_exceeds_field(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute([
            'paths' => [__DIR__ . '/../Fixtures/simple_function.php'],
            '--format' => 'json',
        ]);

        $data = json_decode($tester->getDisplay(), true);

        self::assertNotNull($data);
        self::assertTrue($data['summary']['passed']);
        self::assertNull($data['summary']['threshold']);
        self::assertArrayNotHasKey('exceedsThreshold', $data['files'][0]);
    }

    #[Test]
    public function alpha_option_propagates_to_config(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute([
            'paths' => [__DIR__ . '/../Fixtures/simple_function.php'],
            '--format' => 'json',
            '--alpha' => '0.5',
        ]);

        $data = json_decode($tester->getDisplay(), true);

        self::assertNotNull($data);
        self::assertEqualsWithDelta(0.5, $data['config']['alpha'], 0.001);
    }

    #[Test]
    public function invalid_format_returns_exit_2(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute([
            'paths' => [__DIR__ . '/../Fixtures/simple_function.php'],
            '--format' => 'xml',
        ]);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('Invalid format', $tester->getDisplay());
    }

    #[Test]
    public function invalid_backend_returns_exit_2(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute([
            'paths' => [__DIR__ . '/../Fixtures/simple_function.php'],
            '--backend' => 'anthropic',
        ]);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('Invalid backend', $tester->getDisplay());
    }
}
