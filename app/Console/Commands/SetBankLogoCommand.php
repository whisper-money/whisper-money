<?php

namespace App\Console\Commands;

use App\Models\Bank;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class SetBankLogoCommand extends Command
{
    protected $signature = 'banks:set-logo
                            {bank : The UUID of the bank}
                            {url : The image URL to download}';

    protected $description = 'Download an image from a URL, process it, and set it as the logo for a bank';

    public function handle(): int
    {
        $bank = Bank::query()->find($this->argument('bank'));

        if ($bank === null) {
            $this->error("Bank not found: {$this->argument('bank')}");

            return self::FAILURE;
        }

        $url = $this->argument('url');

        try {
            $response = Http::timeout(30)->get($url);
        } catch (ConnectionException $e) {
            $this->error("Failed to connect to URL: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($response->failed()) {
            $this->error("Failed to download image: HTTP {$response->status()}");

            return self::FAILURE;
        }

        $contentType = strtolower((string) $response->header('Content-Type'));

        if (! str_starts_with($contentType, 'image/')) {
            $this->error("URL does not point to an image (Content-Type: {$contentType}).");

            return self::FAILURE;
        }

        try {
            $manager = new ImageManager(new Driver);
            $image = $manager->read($response->body());
        } catch (\Exception $e) {
            $this->error("Downloaded file is not a valid image: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($image->width() !== $image->height()) {
            $this->error("Image is not square ({$image->width()}×{$image->height()}) — skipping.");

            return self::FAILURE;
        }

        $originalSize = $image->width();

        $this->info("Image downloaded ({$originalSize}×{$originalSize}px).");

        if ($image->width() > 250) {
            $image->scaleDown(250, 250);
            $this->info("Resized to {$image->width()}×{$image->height()}px.");
        }

        $path = "banks/logos/{$bank->id}.png";

        Storage::disk('public')->put($path, $image->toPng()->toString());

        $logoUrl = Storage::disk('public')->url($path);

        $bank->update(['logo' => $logoUrl]);

        $this->info("Logo updated for \"{$bank->name}\".");
        $this->info("Stored at: {$logoUrl}");

        return self::SUCCESS;
    }
}
