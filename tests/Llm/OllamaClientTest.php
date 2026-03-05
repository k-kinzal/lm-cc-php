<?php

declare(strict_types=1);

namespace Lmcc\Tests\Llm;

use Lmcc\Llm\OllamaClient;
use Lmcc\Llm\TokenEntropy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OllamaClientTest extends TestCase
{
    /**
     * Build a chat completions response matching Ollama's /v1/chat/completions format.
     *
     * @param array<int, array{token: string, logprob: float, top_logprobs: array<int, array{token: string, logprob: float}>}> $tokenLogprobs
     */
    private function buildChatResponse(array $tokenLogprobs): string
    {
        $content = '';
        foreach ($tokenLogprobs as $t) {
            $content .= $t['token'];
        }

        return json_encode([
            'id' => 'chatcmpl-1',
            'object' => 'chat.completion',
            'model' => 'codellama',
            'choices' => [
                [
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => $content],
                    'finish_reason' => 'stop',
                    'logprobs' => [
                        'content' => $tokenLogprobs,
                    ],
                ],
            ],
        ]);
    }

    #[Test]
    public function success_returns_correct_token_entropies(): void
    {
        $responseBody = $this->buildChatResponse([
            [
                'token' => 'hello',
                'logprob' => -0.1,
                'top_logprobs' => [
                    ['token' => 'hello', 'logprob' => -0.1],
                    ['token' => 'hi', 'logprob' => -2.5],
                    ['token' => 'hey', 'logprob' => -3.0],
                ],
            ],
            [
                'token' => ' world',
                'logprob' => -0.5,
                'top_logprobs' => [
                    ['token' => ' world', 'logprob' => -0.5],
                    ['token' => ' there', 'logprob' => -1.2],
                ],
            ],
        ]);

        $mockResponse = new MockResponse($responseBody, ['http_code' => 200]);
        $httpClient = new MockHttpClient($mockResponse);

        $client = new OllamaClient($httpClient, 'http://localhost:11434', 'llama3');
        $result = $client->getTokenEntropies('hello world');

        self::assertCount(2, $result);

        self::assertInstanceOf(TokenEntropy::class, $result[0]);
        self::assertSame('hello', $result[0]->token);
        self::assertSame(0, $result[0]->offset);

        self::assertInstanceOf(TokenEntropy::class, $result[1]);
        self::assertSame(' world', $result[1]->token);
        self::assertSame(5, $result[1]->offset);
    }

    #[Test]
    public function offset_reconstruction_from_token_strings(): void
    {
        $responseBody = $this->buildChatResponse([
            [
                'token' => 'function',
                'logprob' => -0.1,
                'top_logprobs' => [['token' => 'function', 'logprob' => -0.1]],
            ],
            [
                'token' => ' foo',
                'logprob' => -0.5,
                'top_logprobs' => [['token' => ' foo', 'logprob' => -0.5]],
            ],
            [
                'token' => '()',
                'logprob' => -0.2,
                'top_logprobs' => [['token' => '()', 'logprob' => -0.2]],
            ],
            [
                'token' => ' {}',
                'logprob' => -0.3,
                'top_logprobs' => [['token' => ' {}', 'logprob' => -0.3]],
            ],
        ]);

        $mockResponse = new MockResponse($responseBody, ['http_code' => 200]);
        $httpClient = new MockHttpClient($mockResponse);

        $client = new OllamaClient($httpClient, 'http://localhost:11434', 'codellama');
        $result = $client->getTokenEntropies('function foo() {}');

        self::assertCount(4, $result);
        self::assertSame(0, $result[0]->offset);
        self::assertSame(8, $result[1]->offset);
        self::assertSame(12, $result[2]->offset);
        self::assertSame(14, $result[3]->offset);
    }

    #[Test]
    public function entropy_calculation_is_correct(): void
    {
        $responseBody = $this->buildChatResponse([
            [
                'token' => 'test',
                'logprob' => -0.1,
                'top_logprobs' => [
                    ['token' => 'test', 'logprob' => -0.1],
                    ['token' => 'other', 'logprob' => -2.5],
                    ['token' => 'another', 'logprob' => -3.0],
                ],
            ],
        ]);

        $mockResponse = new MockResponse($responseBody, ['http_code' => 200]);
        $httpClient = new MockHttpClient($mockResponse);

        $client = new OllamaClient($httpClient, 'http://localhost:11434', 'llama3');
        $result = $client->getTokenEntropies('test');

        self::assertCount(1, $result);

        $lp1 = -0.1;
        $lp2 = -2.5;
        $lp3 = -3.0;
        $expected = -(exp($lp1) * $lp1 + exp($lp2) * $lp2 + exp($lp3) * $lp3) / log(2);

        self::assertEqualsWithDelta($expected, $result[0]->entropy, 0.0001);
    }

    #[Test]
    public function empty_code_returns_empty_array(): void
    {
        $httpClient = new MockHttpClient();

        $client = new OllamaClient($httpClient, 'http://localhost:11434', 'llama3');
        $result = $client->getTokenEntropies('');

        self::assertSame([], $result);
    }

    #[Test]
    public function missing_logprobs_throws_runtime_exception(): void
    {
        $responseBody = json_encode([
            'choices' => [
                [
                    'message' => ['content' => 'test code'],
                    'logprobs' => null,
                ],
            ],
        ]);

        $mockResponse = new MockResponse($responseBody, ['http_code' => 200]);
        $httpClient = new MockHttpClient($mockResponse);

        $client = new OllamaClient($httpClient, 'http://localhost:11434', 'llama3');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no per-token logprobs/');

        $client->getTokenEntropies('test code');
    }

    #[Test]
    public function api_error_throws_runtime_exception(): void
    {
        $mockResponse = new MockResponse('{"error": "model not found"}', ['http_code' => 404]);
        $httpClient = new MockHttpClient($mockResponse);

        $client = new OllamaClient($httpClient, 'http://localhost:11434', 'nonexistent');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/HTTP 404/');

        $client->getTokenEntropies('test code');
    }

    #[Test]
    public function invalid_json_response_throws_runtime_exception(): void
    {
        $mockResponse = new MockResponse('not valid json{{{', ['http_code' => 200]);
        $httpClient = new MockHttpClient($mockResponse);

        $client = new OllamaClient($httpClient, 'http://localhost:11434', 'llama3');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/invalid JSON/');

        $client->getTokenEntropies('test code');
    }

    #[Test]
    public function mismatch_throws_runtime_exception(): void
    {
        $responseBody = json_encode([
            'choices' => [
                [
                    'message' => ['content' => 'completely different text'],
                    'logprobs' => [
                        'content' => [
                            [
                                'token' => 'completely',
                                'logprob' => -0.5,
                                'top_logprobs' => [['token' => 'completely', 'logprob' => -0.5]],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        // Need enough responses for retries (initial + MAX_RETRIES = 4)
        $responses = array_fill(0, 4, new MockResponse($responseBody, ['http_code' => 200]));
        $httpClient = new MockHttpClient($responses);

        $client = new OllamaClient($httpClient, 'http://localhost:11434', 'llama3');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/aligned/');

        $client->getTokenEntropies('original code');
    }

    #[Test]
    public function leading_whitespace_is_handled(): void
    {
        // Model adds a leading space before reproducing the code
        $responseBody = $this->buildChatResponse([
            [
                'token' => ' ',
                'logprob' => -0.5,
                'top_logprobs' => [['token' => ' ', 'logprob' => -0.5]],
            ],
            [
                'token' => 'hello',
                'logprob' => -0.1,
                'top_logprobs' => [['token' => 'hello', 'logprob' => -0.1]],
            ],
            [
                'token' => ' world',
                'logprob' => -0.3,
                'top_logprobs' => [['token' => ' world', 'logprob' => -0.3]],
            ],
        ]);

        $mockResponse = new MockResponse($responseBody, ['http_code' => 200]);
        $httpClient = new MockHttpClient($mockResponse);

        $client = new OllamaClient($httpClient, 'http://localhost:11434', 'llama3');
        $result = $client->getTokenEntropies('hello world');

        self::assertCount(2, $result);
        self::assertSame('hello', $result[0]->token);
        self::assertSame(0, $result[0]->offset);
        self::assertSame(' world', $result[1]->token);
        self::assertSame(5, $result[1]->offset);
    }
}
