<?php

namespace App\Services\Discord;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DiscordWebhook
{
    public function __construct(private ?string $webhookUrl) {}

    /**
     * @param  array<int, array<string, mixed>>  $embeds
     */
    public function send(string $content = '', array $embeds = []): void
    {
        if (blank($this->webhookUrl)) {
            Log::warning('Discord webhook URL not configured, skipping message.');

            return;
        }

        $payload = array_filter([
            'content' => $content !== '' ? $content : null,
            'embeds' => $embeds !== [] ? $embeds : null,
        ]);

        $response = Http::asJson()->post($this->webhookUrl, $payload);

        if ($response->failed()) {
            Log::warning('Discord webhook request failed.', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }
}
