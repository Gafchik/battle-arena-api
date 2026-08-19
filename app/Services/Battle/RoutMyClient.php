<?php

namespace App\Services\Battle;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RoutMyClient
{
    /**
     * Ask a model to reply and return the raw text content, or null on any failure
     * (HTTP error, upstream error payload, missing content). Callers must have a
     * fallback for the null case — this never throws for upstream problems.
     */
    public function complete(string $model, string $prompt, int $maxTokens = 100): ?string
    {
        try {
            $response = Http::withToken(config('services.routmy.api_key'))
                ->timeout(20)
                ->post(config('services.routmy.base_url').'/chat/completions', [
                    'model' => $model,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                    'max_tokens' => $maxTokens,
                ]);

            if ($response->failed() || $response->json('error')) {
                Log::warning('rout.my request failed', [
                    'model' => $model,
                    'status' => $response->status(),
                    'body' => $response->json('error') ?? $response->body(),
                ]);

                return null;
            }

            return $response->json('choices.0.message.content');
        } catch (\Throwable $e) {
            Log::warning('rout.my request threw', ['model' => $model, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
