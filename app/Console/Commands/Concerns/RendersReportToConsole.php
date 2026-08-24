<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Console\Command;

/**
 * Render Discord report embeds as plain console output, for the --no-discord
 * flag on the stats:* report commands.
 *
 * @mixin Command
 */
trait RendersReportToConsole
{
    /**
     * @param  array<int, array<string, mixed>>  $embeds
     */
    protected function printEmbeds(array $embeds): void
    {
        foreach ($embeds as $embed) {
            if (! empty($embed['title'])) {
                $this->newLine();
                $this->line('<options=bold>'.$this->plainText($embed['title']).'</>');
            }

            if (! empty($embed['description'])) {
                $this->line($this->plainText($embed['description']));
            }

            foreach ($embed['fields'] ?? [] as $field) {
                $this->newLine();
                $this->line('<options=bold>'.$this->plainText($field['name']).'</>');
                $this->line($this->plainText($field['value']));
            }
        }
    }

    /**
     * Strip Discord markdown (code fences, bold) for readable console output.
     */
    private function plainText(string $text): string
    {
        return trim(str_replace(['```', '**'], '', $text));
    }
}
