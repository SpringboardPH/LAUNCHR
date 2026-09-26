<?php

namespace App\Services\BIR\Llm;

use RuntimeException;

/**
 * Stand-in for a real provider. Two jobs:
 *
 *   1. Tests queue an exact reply and assert how the service handled it.
 *      No network, no cost, no flakiness in CI.
 *   2. Local development works before the API key is procured — the default
 *      reply is a complete, valid 1601-C intent.
 *
 * This is the bound implementation unless BIR_LLM_DRIVER=anthropic, so a
 * misconfigured environment costs nothing rather than billing silently.
 */
class FakeLlmClient implements LlmClientInterface
{
    /** @var array<int,array<string,mixed>> */
    private array $queue = [];

    /** @var array<int,array{system:string,message:string}> */
    private array $calls = [];

    private bool $shouldFail = false;

    /** Queue a reply for the next call. Chainable for multi-turn tests. */
    public function willReturn(array $reply): self
    {
        $this->queue[] = $reply;

        return $this;
    }

    /** Make the next call throw, so failure handling can be tested. */
    public function willFail(): self
    {
        $this->shouldFail = true;

        return $this;
    }

    /** What the service actually sent — lets tests assert on the prompt. */
    public function calls(): array
    {
        return $this->calls;
    }

    public function structured(string $systemPrompt, string $userMessage, array $schema): array
    {
        $this->calls[] = ['system' => $systemPrompt, 'message' => $userMessage];

        if ($this->shouldFail) {
            $this->shouldFail = false;
            throw new RuntimeException('FakeLlmClient: simulated provider failure.');
        }

        if ($this->queue !== []) {
            return array_shift($this->queue);
        }

        return [
            'form_type' => '1601-C',
            'tax_year' => 2026,
            'tax_month' => 8,
            'employee_query' => null,
            'confidence' => 'high',
            'clarification' => null,
        ];
    }
}
