<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\URL;

class GenerateLandingAuthLinkCommand extends Command
{
    protected $signature = 'landing:auth-link
        {--days=7 : Number of days before the link expires}';

    protected $description = 'Generate a signed landing page link that unlocks authentication';

    public function handle(): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);

        if ($days === false || $days < 1) {
            $this->error('Days must be a positive integer.');

            return self::FAILURE;
        }

        $url = URL::temporarySignedRoute('home', now()->addDays($days), [
            config('landing.auth_override.query_parameter', 'signup') => 1,
        ]);

        $this->line($url);

        return self::SUCCESS;
    }
}
