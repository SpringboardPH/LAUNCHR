<?php

namespace Tests\Feature\Bir;

use App\Services\BIR\Llm\OllamaClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Ollama Cloud needs a Bearer key and ignores `format`, so the reply can be JSON
 * wrapped in prose or a fence. A local Ollama gets no Authorization header at all.
 */
class BirOllamaClientTest extends TestCase
{
    private const SCHEMA = ['type' => 'object', 'properties' => ['form' => ['type' => 'string']]];

    private function reply(string $content): void
    {
        Http::fake(['*/api/chat' => Http::response(['message' => ['content' => $content]])]);
    }

    public function test_sends_the_key_as_a_bearer_token_when_set(): void
    {
        $this->reply('{"form":"1601-C"}');

        (new OllamaClient('https://ollama.com', 'gpt-oss:120b', 'secret'))->structured('sys', 'hi', self::SCHEMA);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://ollama.com/api/chat'
            && $r->hasHeader('Authorization', 'Bearer secret'));
    }

    public function test_sends_no_authorization_header_without_a_key(): void
    {
        $this->reply('{"form":"1601-C"}');

        (new OllamaClient('http://localhost:11434', 'qwen2.5:7b'))->structured('sys', 'hi', self::SCHEMA);

        Http::assertSent(fn (Request $r) => !$r->hasHeader('Authorization'));
    }

    public function test_puts_the_schema_in_the_system_prompt(): void
    {
        $this->reply('{"form":"1601-C"}');

        (new OllamaClient('https://ollama.com', 'gpt-oss:120b', 'secret'))->structured('sys', 'hi', self::SCHEMA);

        Http::assertSent(fn (Request $r) => str_contains($r['messages'][0]['content'], 'Reply ONLY with a JSON object')
            && str_contains($r['messages'][0]['content'], '"form"'));
    }

    public function test_reads_json_wrapped_in_a_fence_or_prose(): void
    {
        $this->reply("Sure:\n```json\n{\"form\": \"1601-C\"}\n```");

        $result = (new OllamaClient('https://ollama.com', 'gpt-oss:120b', 'secret'))->structured('sys', 'hi', self::SCHEMA);

        $this->assertSame(['form' => '1601-C'], $result);
    }

    public function test_plain_prose_is_no_usable_result(): void
    {
        $this->reply('The form is **BIR Form 1601-C**.');

        $this->expectExceptionObject(new RuntimeException('The assistant returned no usable result.'));

        (new OllamaClient('https://ollama.com', 'gpt-oss:120b', 'secret'))->structured('sys', 'hi', self::SCHEMA);
    }
}
