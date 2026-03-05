<?php

declare(strict_types=1);

namespace Lmcc\Llm;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OpenAiClient implements LlmClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $model,
    ) {
    }

    public function getTokenEntropies(string $code): array
    {
        if ($code === '') {
            return [];
        }

        try {
            $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/') . '/v1/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ],
                'json' => [
                    'model' => $this->model,
                    'prompt' => $code,
                    'max_tokens' => 0,
                    'echo' => true,
                    'logprobs' => 5,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 400) {
                throw new \RuntimeException(sprintf('OpenAI API returned HTTP %d', $statusCode));
            }

            $body = $response->getContent();
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException('OpenAI API request failed: ' . $e->getMessage(), 0, $e);
        }

        try {
            $data = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('OpenAI API returned invalid JSON: ' . $e->getMessage(), 0, $e);
        }

        return $this->parseResponse($data);
    }

    /**
     * @return TokenEntropy[]
     */
    private function parseResponse(array $data): array
    {
        if (!isset($data['choices'][0]['logprobs'])) {
            throw new \RuntimeException('OpenAI API response missing required field: choices[0].logprobs');
        }

        $logprobs = $data['choices'][0]['logprobs'];
        $tokenStrings = $logprobs['tokens'] ?? [];
        $tokenLogprobs = $logprobs['token_logprobs'] ?? [];
        $topLogprobs = $logprobs['top_logprobs'] ?? [];
        $textOffsets = $logprobs['text_offset'] ?? [];

        if (!is_array($tokenStrings)) {
            throw new \RuntimeException('OpenAI API response: tokens field is not an array');
        }

        $results = [];

        foreach ($tokenStrings as $i => $token) {
            $tokenStr = (string) $token;
            $offset = (int) ($textOffsets[$i] ?? 0);

            $lp = $tokenLogprobs[$i] ?? null;
            $topLp = $topLogprobs[$i] ?? null;

            if ($lp === null) {
                // First token typically has null logprob
                $entropy = 0.0;
            } elseif (is_array($topLp) && count($topLp) > 0) {
                $entropy = $this->computeEntropyFromTopLogprobs($topLp);
            } else {
                // Fallback: single logprob
                $entropy = -(float) $lp / log(2);
            }

            $results[] = new TokenEntropy(
                token: $tokenStr,
                entropy: $entropy,
                offset: $offset,
            );
        }

        return $results;
    }

    private function computeEntropyFromTopLogprobs(array $topLogprobs): float
    {
        $entropy = 0.0;

        foreach ($topLogprobs as $lp) {
            if (!is_numeric($lp)) {
                continue;
            }
            $lp = (float) $lp;
            $p = exp($lp);
            if ($p > 0) {
                $entropy -= $p * $lp / log(2);
            }
        }

        return $entropy;
    }
}
