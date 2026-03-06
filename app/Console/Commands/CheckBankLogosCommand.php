<?php

namespace App\Console\Commands;

use App\Mail\BrokenBankLogosReportEmail;
use App\Models\Bank;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class CheckBankLogosCommand extends Command
{
    protected $signature = 'banks:check-logos';

    protected $description = 'Validate bank logo URLs and clear broken links';

    public function handle(): int
    {
        $banks = Bank::query()
            ->whereNotNull('logo')
            ->get(['id', 'name', 'logo']);

        if ($banks->isEmpty()) {
            $this->info('No bank logos found to validate.');

            return self::SUCCESS;
        }

        $updatedBanks = [];

        foreach ($banks as $bank) {
            $logoUrl = (string) $bank->logo;

            if ($this->hasWorkingImage($logoUrl)) {
                continue;
            }

            $bank->update(['logo' => null]);

            $updatedBanks[] = [
                'id' => $bank->id,
                'name' => $bank->name,
                'previous_logo' => $logoUrl,
            ];

            $this->warn("Cleared broken logo for {$bank->name}.");
        }

        if ($updatedBanks === []) {
            $this->info('All bank logos are valid.');

            return self::SUCCESS;
        }

        $updatedCount = count($updatedBanks);

        $this->info("Cleared broken logos for {$updatedCount} bank(s).");

        $adminEmail = (string) config('mail.admin_email');

        if ($adminEmail === '') {
            $this->warn('ADMIN_EMAIL is not configured. Skipping report email.');

            return self::SUCCESS;
        }

        Mail::to($adminEmail)->send(new BrokenBankLogosReportEmail($updatedBanks));

        $this->info("Sent broken logo report to {$adminEmail}.");

        return self::SUCCESS;
    }

    private function hasWorkingImage(string $logoUrl): bool
    {
        if (! filter_var($logoUrl, FILTER_VALIDATE_URL)) {
            return false;
        }

        try {
            $headResponse = Http::timeout(10)->head($logoUrl);

            if ($headResponse->successful() && $this->isImageResponse($headResponse)) {
                return true;
            }

            if ($headResponse->failed() && $headResponse->status() !== 405) {
                return false;
            }

            $getResponse = Http::timeout(10)->get($logoUrl);

            return $getResponse->successful() && $this->isImageResponse($getResponse);
        } catch (ConnectionException) {
            return false;
        }
    }

    private function isImageResponse(Response $response): bool
    {
        $contentType = strtolower((string) $response->header('Content-Type'));

        if ($contentType === '') {
            return false;
        }

        return str_starts_with($contentType, 'image/')
            || str_contains($contentType, 'application/octet-stream');
    }
}
