<?php

namespace App\Providers;

use App\Services\BIR\Llm\AnthropicClient;
use App\Services\BIR\Llm\FakeLlmClient;
use App\Services\BIR\Llm\LlmClientInterface;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Which LLM implementation the BIR assistant gets. Defaults to the
        // fake so a machine without an API key still works and nothing bills
        // by accident — set BIR_LLM_DRIVER=anthropic in .env to go live.
        $this->app->bind(LlmClientInterface::class, function () {
            if (config('services.bir.llm_driver') === 'anthropic') {
                return new AnthropicClient(
                    (string) config('services.anthropic.key'),
                    (string) config('services.anthropic.model'),
                );
            }

            return new FakeLlmClient();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}