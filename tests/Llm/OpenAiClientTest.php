<?php

declare(strict_types=1);

namespace Lmcc\Tests\Llm;

use Lmcc\Llm\OpenAiClient;
use Lmcc\Llm\TokenEntropy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenAiClientTest extends TestCase
{
    #[Test]
    public function success_returns_correct_token_entropies(): void
    {
        $responseBody = json_encode([
            'choices' => [
                [
                    'logprobs' => [
                        'tokens' => ['func', 'tion', ' foo'],
                        'token_logprobs' => [null, -0.5, -1.2],
                        'top_logprobs' => [
                            null,
                            ['tion' => -0.5, 'ting' => -2.0],
                            [' foo' => -1.2, ' bar' => -1.8],
                        ],
                        'text_offset' => [0, 4, 8],
                    ],
                ],
            ],
        ]);

        $mockResponse = new MockResponse($responseBody, ['http_code' => 200]);
        $httpClient = new MockHttpClient($mockResponse);

        $client = new OpenAiClient($httpClient, 'https://api.openai.com', 'sk-test', 'gpt-3.5-turbo');
        $result = $client->getTokenEntropies('function foo');

        self::assertCount(3, $result);
        self::assertInstanceOf(TokenEntropy::class, $result[0]);

        self::assertSame('func', $result[0]->token);
        self::assertSame(0, $result[0]->offset);
        self::assertEqualsWithDelta(0.0, $result[0]->entropy, 0.0001);

        self::assertSame('tion', $result[1]->token);
        self::assertSame(4, $result[1]->offset);
        self::assertGreaterThan(0.0, $result[1]->entropy);

        self::assertSame(' foo', $result[2]->token);
        self::assertSame(8, $result[2]->offset);
        self::assertGreaterThan(0.0, $result[2]->entropy);
    }

    #[Test]
    public function entropy_calculation_from_top_logprobs(): void
    {
        $responseBody = json_encode([
            'choices' => [
                [
                    'logprobs' => [
                        'tokens' => ['test'],
                        'token_logprobs' => [-0.1],
                        'top_logprobs' => [
                            ['test' => -0.1, 'other' => -2.5, 'another' => -3.0],
                        ],
                        'text_offset' => [0],
                    ],
                ],
            ],
        ]);

        $mockResponse = new MockResponse($responseBody, ['http_code' => 200]);
        $httpClient = new MockHttpClient($mockResponse);

        $client = new OpenAiClient($httpClient, 'https://api.openai.com', 'sk-test', 'gpt-3.5-turbo');
        $result = $client->getTokenEntropies('test');

        $lp1 = -0.1;
        $lp2 = -2.5;
        $lp3 = -3.0;
        $expected = -(exp($lp1) * $lp1 + exp($lp2) * $lp2 + exp($lp3) * $lp3) / log(2);

        self::assertCount(1, $result);
        self::assertEqualsWithDelta($expected, $result[0]->entropy, 0.0001);
    }

    #[Test]
    public function text_offsets_used_directly(): void
    {
        $responseBody = json_encode([
            'choices' => [
                [
                    'logprobs' => [
                        'tokens' => ['if', ' (', 'true', ')'],
                        'token_logprobs' => [null, -0.3, -0.1, -0.05],
                        'top_logprobs' => [null, [' (' => -0.3], ['true' => -0.1], [')' => -0.05]],
                        'text_offset' => [0, 2, 4, 8],
                    ],
                ],
            ],
        ]);

        $mockResponse = new MockResponse($responseBody, ['http_code' => 200]);
        $httpClient = new MockHttpClient($mockResponse);

        $client = new OpenAiClient($httpClient, 'https://api.openai.com', 'sk-test', 'gpt-3.5-turbo');
        $result = $client->getTokenEntropies('if (true)');

        self::assertSame(0, $result[0]->offset);
        self::assertSame(2, $result[1]->offset);
        self::assertSame(4, $result[2]->offset);
        self::assertSame(8, $result[3]->offset);
    }

    #[Test]
    public function empty_code_returns_empty_array(): void
    {
        $httpClient = new MockHttpClient();

        $client = new OpenAiClient($httpClient, 'https://api.openai.com', 'sk-test', 'gpt-3.5-turbo');
        $result = $client->getTokenEntropies('');

        self::assertSame([], $result);
    }

    #[Test]
    public function api_error_throws_runtime_exception(): void
    {
        $mockResponse = new MockResponse('{"error": {"message": "Unauthorized"}}', ['http_code' => 401]);
        $httpClient = new MockHttpClient($mockResponse);

        $client = new OpenAiClient($httpClient, 'https://api.openai.com', 'bad-key', 'gpt-3.5-turbo');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/HTTP 401/');

        $client->getTokenEntropies('test code');
    }

    #[Test]
    public function invalid_json_response_throws_runtime_exception(): void
    {
        $mockResponse = new MockResponse('not json at all', ['http_code' => 200]);
        $httpClient = new MockHttpClient($mockResponse);

        $client = new OpenAiClient($httpClient, 'https://api.openai.com', 'sk-test', 'gpt-3.5-turbo');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/invalid JSON/');

        $client->getTokenEntropies('test code');
    }
}
