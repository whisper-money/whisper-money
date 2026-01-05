<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Facades\File;

trait ResolvesFeatures
{
    private function resolveFeatureClass(string $name): ?string
    {
        $featureClass = "App\\Features\\{$name}";

        if (class_exists($featureClass)) {
            return $featureClass;
        }

        return null;
    }

    private function getAvailableFeatures(): string
    {
        $featuresPath = app_path('Features');

        if (! File::isDirectory($featuresPath)) {
            return 'None';
        }

        $files = File::files($featuresPath);
        $features = [];

        foreach ($files as $file) {
            $features[] = $file->getFilenameWithoutExtension();
        }

        return implode(', ', $features) ?: 'None';
    }
}
