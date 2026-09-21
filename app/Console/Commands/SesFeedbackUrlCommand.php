<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\URL;

class SesFeedbackUrlCommand extends Command
{
    protected $signature = 'ses:feedback-url';

    protected $description = 'Print the signed URL to subscribe to the SES bounce and complaint SNS topic';

    public function handle(): int
    {
        $this->line(URL::signedRoute('ses.feedback'));

        return self::SUCCESS;
    }
}
