<?php

namespace App\Console\Commands;

use App\Services\LandingAuthOverrideService;
use Illuminate\Console\Command;

class GenerateLandingAuthLinkCommand extends Command
{
    public function __construct(private LandingAuthOverrideService $landingAuthOverrideService)
    {
        parent::__construct();
    }

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

        $url = $this->landingAuthOverrideService->generateSignedUrl($days);

        $this->line($url);

        return self::SUCCESS;
    }
}
