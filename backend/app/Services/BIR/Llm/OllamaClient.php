<?php

namespace App\Services\BIR\Llm;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Self-hosted provider. Not bound unless BIR_LLM_DRIVER=ollama.
 *
 * The typed message never leaves our own network. Structured output comes from
 * Ollama's `format`, which constrains the reply to the JSON schema.
 */
class OllamaClient implements LlmClientInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeout = 60, // local CPU inference is slower than a hosted API
    ) {}

    public function structured(string $systemPrompt, string $userMessage, array $schema): array
    {
        try {
            $response = Http::timeout($this->timeout)->post(rtrim($this->baseUrl, '/') . '/api/chat', [
                'model' => $this->model,
                'stream' => false,
                'format' => $schema,
                'options' => ['temperature' => 0],
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userMessage],
                ],
            ]);
        } catch (ConnectionException) {
            // Ollama not running is the most likely failure — it must become the 503, not a 500.
            throw new RuntimeException('The assistant is unavailable right now.');
        }

        if ($response->failed()) {
            Log::warning('BIR assistant provider call failed', ['status' => $response->status()]);

            throw new RuntimeException('The assistant is unavailable right now.');
        }

        $decoded = json_decode((string) $response->json('message.content'), true);

        if (!is_array($decoded)) {
            throw new RuntimeException('The assistant returned no usable result.');
        }

        return $decoded;
    }
}