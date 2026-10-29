<?php

namespace App\Console\Commands;

use App\Support\Documentation\ApiReference;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class GenerateApiDocsCommand extends Command
{
    protected $signature = 'api:docs';

    protected $description = 'Write the API reference pages (resources/docs/documentation/60-integrations/20-api) from the OpenAPI spec';

    public function handle(): int
    {
        $root = (string) config('documentation.root');
        $pages = ApiReference::current()->pages();

        // A group that no longer exists leaves its page behind otherwise.
        File::deleteDirectory($root.'/'.ApiReference::GroupsDirectory);

        foreach ($pages as $path => $contents) {
            File::ensureDirectoryExists(dirname($root.'/'.$path));
            File::put($root.'/'.$path, $contents);
            $this->line('Wrote '.$path);
        }

        $this->info('API reference written: '.count($pages).' pages.');

        return self::SUCCESS;
    }
}
