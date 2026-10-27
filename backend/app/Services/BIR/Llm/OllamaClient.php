<?php

namespace App\Services\BIR\Llm;

use App\Services\BIR\BirTokenUsage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Ollama provider. Not bound unless BIR_LLM_DRIVER=ollama.
 *
 * Self-hosted (no key) keeps the typed message on our own network. Ollama Cloud
 * (OLLAMA_URL=https://ollama.com plus OLLAMA_API_KEY) sends it to ollama.com.
 *
 * Structured output: `format` constrains the reply on a local Ollama, but Ollama
 * Cloud ignores it, so the schema is also spelled out in the system prompt and
 * the JSON object is pulled out of whatever text surrounds it.
 */
class OllamaClient implements LlmClientInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly string $apiKey = '',
        private readonly int $timeout = 60, // local CPU inference is slower than a hosted API
    ) {}

    public function structured(string $systemPrompt, string $userMessage, array $schema): array
    {
        $systemPrompt .= "\n\nReply ONLY with a JSON object matching this JSON schema. No other text.\n"
            . json_encode($schema, JSON_UNESCAPED_SLASHES);

        try {
            $response = Http::timeout($this->timeout)
                ->when($this->apiKey !== '', fn ($request) => $request->withToken($this->apiKey))
                ->post(rtrim($this->baseUrl, '/') . '/api/chat', [
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

        BirTokenUsage::record((int) $response->json('prompt_eval_count', 0), (int) $response->json('eval_count', 0));
        $decoded = $this->decodeObject((string) $response->json('message.content'));

        if ($decoded === null) {
            throw new RuntimeException('The assistant returned no usable result.');
        }

        return $decoded;
    }

    /**
     * The reply as a JSON object, tolerating a ```json fence or a sentence around it.
     *
     * @return array<string,mixed>|null
     */
    private function decodeObject(string $content): ?array
    {
        $decoded = json_decode(trim($content), true);

        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($content, '{');
        $end = strrpos($content, '}');

        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $decoded = json_decode(substr($content, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }
}
