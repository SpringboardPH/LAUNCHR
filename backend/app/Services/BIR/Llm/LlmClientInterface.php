<?php

namespace App\Services\BIR\Llm;

/**
 * The only thing the rest of the BIR feature knows about a language model.
 *
 * Implementations handle transport and parsing; callers get structured data
 * back. Swapping providers — or self-hosting — is a config change, never a
 * change to BirIntentService or anything downstream of it.
 */
interface LlmClientInterface
{
    /**
     * Ask the model to return data matching $schema.
     *
     * @param  string  $systemPrompt  What the model is for and what it must not do
     * @param  string  $userMessage   The person's own words, unmodified
     * @param  array<string,mixed>  $schema  JSON-schema description of the reply
     * @return array<string,mixed>
     *
     * @throws \RuntimeException when the provider fails or returns nothing usable
     */
    public function structured(string $systemPrompt, string $userMessage, array $schema): array;
}
