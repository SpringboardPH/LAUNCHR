<?php

namespace App\Services\BIR\Llm;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Real provider. Not bound unless BIR_LLM_DRIVER=anthropic.
 *
 * Structured output comes from tool use rather than asking for JSON in prose:
 * the schema is declared up front and tool_choice makes it mandatory, so the
 * reply is schema-shaped instead of text that has to be hopefully parsed.
 *
 * Never handles payroll figures. It receives the person's typed message and
 * nothing else, which is what keeps employee data on our own servers.
 */
class AnthropicClient implements LlmClientInterface
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const TOOL_NAME = 'record_intent';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $timeout = 20,
    ) {}

    public function structured(string $systemPrompt, string $userMessage, array $schema): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('No API key configured for the BIR assistant.');
        }

        $response = Http::timeout($this->timeout)
            ->retry(2, 500, throw: false)
            ->withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])
            ->post(self::ENDPOINT, [
                'model' => $this->model,
                'max_tokens' => 512,
                'system' => $systemPrompt,
                'messages' => [
                    ['role' => 'user', 'content' => $userMessage],
                ],
                'tools' => [[
                    'name' => self::TOOL_NAME,
                    'description' => 'Record the parsed BIR form request.',
                    'input_schema' => $schema,
                ]],
                'tool_choice' => ['type' => 'tool', 'name' => self::TOOL_NAME],
            ]);

        if ($response->failed()) {
            // Log the status, never the body — the body echoes the user's message.
            Log::warning('BIR assistant provider call failed', [
                'status' => $response->status(),
            ]);

            throw new RuntimeException('The assistant is unavailable right now.');
        }

        foreach ($response->json('content', []) as $block) {
            if (($block['type'] ?? null) === 'tool_use' && is_array($block['input'] ?? null)) {
                return $block['input'];
            }
        }

        throw new RuntimeException('The assistant returned no usable result.');
    }
}
