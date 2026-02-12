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

        if ($this->isStringBasedFeature($name)) {
            return $name;
        }

        return null;
    }

    private function getAvailableFeatures(): string
    {
        $features = [];

        $featuresPath = app_path('Features');

        if (File::isDirectory($featuresPath)) {
            $files = File::files($featuresPath);

            foreach ($files as $file) {
                $features[] = $file->getFilenameWithoutExtension();
            }
        }

        $stringFeatures = $this->getStringBasedFeatures();

        $allFeatures = array_merge($features, $stringFeatures);

        return implode(', ', array_unique($allFeatures)) ?: 'None';
    }

    private function isStringBasedFeature(string $name): bool
    {
        return in_array($name, $this->getStringBasedFeatures(), true);
    }

    private function getStringBasedFeatures(): array
    {
        return ['plaintext-transactions', 'open-banking', 'account-mapping'];
    }
}
