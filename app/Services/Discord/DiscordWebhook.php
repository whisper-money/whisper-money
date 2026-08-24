<?php

namespace App\Services\Discord;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DiscordWebhook
{
    /**
     * Discord's own caps on embed text. Exceeding either one makes Discord
     * reject the whole request, so an over-long report would vanish from the
     * channel with nothing but a logged 400 behind it.
     */
    private const DESCRIPTION_LIMIT = 4096;

    private const FIELD_VALUE_LIMIT = 1024;

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
            'embeds' => $embeds !== [] ? $this->withinLimits($embeds) : null,
        ]);

        $response = Http::asJson()->post($this->webhookUrl, $payload);

        if ($response->failed()) {
            Log::warning('Discord webhook request failed.', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }

    /**
     * Trim embed text that would break Discord's limits. Losing the tail of a
     * legend is a much better outcome than losing the entire report, and the
     * warning says which text needs shortening.
     *
     * @param  array<int, array<string, mixed>>  $embeds
     * @return array<int, array<string, mixed>>
     */
    private function withinLimits(array $embeds): array
    {
        foreach ($embeds as $index => $embed) {
            if (isset($embed['description'])) {
                $embeds[$index]['description'] = $this->trim($embed['description'], self::DESCRIPTION_LIMIT, 'description');
            }

            foreach ($embed['fields'] ?? [] as $field => $data) {
                $embeds[$index]['fields'][$field]['value'] = $this->trim($data['value'], self::FIELD_VALUE_LIMIT, $data['name']);
            }
        }

        return $embeds;
    }

    private function trim(string $text, int $limit, string $label): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        Log::warning('Discord embed text trimmed to fit the limit.', [
            'label' => $label,
            'length' => mb_strlen($text),
            'limit' => $limit,
        ]);

        return mb_substr($text, 0, $limit - 1).'…';
    }
}
