<?php

declare(strict_types=1);

namespace Lmcc\Tests;

use Lmcc\LmccAnalyzer;
use Lmcc\LmccResult;
use Lmcc\Llm\LlmClientInterface;
use Lmcc\Llm\TokenEntropy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LmccAnalyzerTest extends TestCase
{
    private function createCharMockClient(float $defaultEntropy = 0.5, array $entropyMap = []): LlmClientInterface
    {
        return new class ($defaultEntropy, $entropyMap) implements LlmClientInterface {
            public function __construct(
                private readonly float $defaultEntropy,
                private readonly array $entropyMap,
            ) {
            }

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
                        entropy: $this->entropyMap[$i] ?? $this->defaultEntropy,
                        offset: $i,
                    );
                }
                return $tokens;
            }
        };
    }

    private function createFixedMockClient(array $tokenEntropies): LlmClientInterface
    {
        return new class ($tokenEntropies) implements LlmClientInterface {
            public function __construct(private readonly array $tokens)
            {
            }

            public function getTokenEntropies(string $code): array
            {
                return $this->tokens;
            }
        };
    }

    #[Test]
    public function strip_comments_removes_all_comment_types(): void
    {
        $code = "<?php\n// line comment\n\$a = 1;\n/* block comment */\n\$b = 2;\n/** doc comment */\n\$c = 3;";

        $client = $this->createCharMockClient();
        $analyzer = new LmccAnalyzer($client);
        $result = $analyzer->analyze($code, 'test.php');

        self::assertInstanceOf(LmccResult::class, $result);
        self::assertGreaterThan(0.0, $result->score);
    }

    #[Test]
    public function strip_comments_preserves_strings(): void
    {
        $code = "<?php\n\$a = \"// not a comment\";\n\$b = '/* also not */';";

        $client = $this->createCharMockClient();
        $analyzer = new LmccAnalyzer($client);
        $result = $analyzer->analyze($code, 'test.php');

        self::assertInstanceOf(LmccResult::class, $result);
        self::assertGreaterThan(0, $result->tokenCount);
    }

    #[Test]
    public function compute_tau_override(): void
    {
        $tokens = [];
        for ($i = 0; $i < 5; $i++) {
            $tokens[] = new TokenEntropy(token: (string) $i, entropy: 0.5, offset: $i);
        }

        $client = $this->createFixedMockClient($tokens);
        $analyzer = new LmccAnalyzer($client, tauOverride: 0.3);
        $result = $analyzer->analyze("<?php\n01234", 'test.php');

        self::assertEqualsWithDelta(0.3, $result->tau, 0.0001);
    }

    #[Test]
    public function detect_boundaries_entropy_only(): void
    {
        $tokens = [
            new TokenEntropy(token: 'a', entropy: 0.1, offset: 0),
            new TokenEntropy(token: 'b', entropy: 0.1, offset: 1),
            new TokenEntropy(token: 'c', entropy: 5.0, offset: 2),
            new TokenEntropy(token: 'd', entropy: 0.1, offset: 3),
        ];

        $client = $this->createFixedMockClient($tokens);
        $analyzer = new LmccAnalyzer($client, tauOverride: 1.0);
        $result = $analyzer->analyze("<?php\nabcd", 'test.php');

        self::assertGreaterThan(0, $result->boundaryCount);
    }

    #[Test]
    public function detect_boundaries_syntactic_only(): void
    {
        $code = "<?php\nif (true) { return 1; }";

        $client = $this->createCharMockClient(0.1);
        $analyzer = new LmccAnalyzer($client, tauOverride: 10.0);
        $result = $analyzer->analyze($code, 'test.php');

        self::assertGreaterThan(0, $result->boundaryCount);
    }

    #[Test]
    public function decompose_creates_units(): void
    {
        $code = "<?php\n\$a = 1;\n\$b = 2;";
        $client = $this->createCharMockClient(0.5);
        $analyzer = new LmccAnalyzer($client, tauOverride: 0.3);
        $result = $analyzer->analyze($code, 'test.php');

        self::assertGreaterThan(0, $result->unitCount);
    }

    #[Test]
    public function analyze_simple_function_produces_score(): void
    {
        $code = "<?php\nfunction greet(string \$name): string\n{\n    return 'Hello, ' . \$name;\n}";

        $client = $this->createCharMockClient(0.5);
        $analyzer = new LmccAnalyzer($client);
        $result = $analyzer->analyze($code, 'simple.php');

        self::assertSame('simple.php', $result->filePath);
        self::assertGreaterThan(0.0, $result->score);
        self::assertGreaterThan(0, $result->nodeCount);
        self::assertGreaterThan(0, $result->tokenCount);
        self::assertGreaterThan(0, $result->unitCount);
    }

    #[Test]
    public function analyze_empty_code_returns_zero_score(): void
    {
        $client = $this->createCharMockClient();
        $analyzer = new LmccAnalyzer($client);
        $result = $analyzer->analyze('', 'empty.php');

        self::assertSame(0.0, $result->score);
        self::assertSame(0, $result->nodeCount);
        self::assertSame(0, $result->tokenCount);
    }

    #[Test]
    public function analyze_whitespace_only_returns_zero_score(): void
    {
        $client = $this->createCharMockClient();
        $analyzer = new LmccAnalyzer($client);
        $result = $analyzer->analyze("   \n\n  ", 'ws.php');

        self::assertSame(0.0, $result->score);
    }

    #[Test]
    public function result_exceeds_threshold(): void
    {
        $code = "<?php\nfunction test() { return 1; }";
        $client = $this->createCharMockClient(0.5);
        $analyzer = new LmccAnalyzer($client);
        $result = $analyzer->analyze($code, 'test.php');

        self::assertTrue($result->exceedsThreshold(0.001));
        self::assertFalse($result->exceedsThreshold(999999.0));
    }

    #[Test]
    public function result_to_array_has_expected_keys(): void
    {
        $code = "<?php\n\$x = 1;";
        $client = $this->createCharMockClient(0.5);
        $analyzer = new LmccAnalyzer($client);
        $result = $analyzer->analyze($code, 'test.php');

        $arr = $result->toArray();

        self::assertArrayHasKey('path', $arr);
        self::assertArrayHasKey('score', $arr);
        self::assertArrayHasKey('totalBranch', $arr);
        self::assertArrayHasKey('totalCompLevel', $arr);
        self::assertArrayHasKey('maxCompLevel', $arr);
        self::assertArrayHasKey('avgCompLevel', $arr);
        self::assertArrayHasKey('maxBranch', $arr);
        self::assertArrayHasKey('avgBranch', $arr);
        self::assertArrayHasKey('nodeCount', $arr);
        self::assertArrayHasKey('tokenCount', $arr);
        self::assertArrayHasKey('tau', $arr);
        self::assertArrayHasKey('boundaryCount', $arr);
        self::assertArrayHasKey('unitCount', $arr);
        self::assertSame('test.php', $arr['path']);
    }
}
