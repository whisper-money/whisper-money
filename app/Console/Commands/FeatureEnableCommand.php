<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesFeatures;
use App\Models\User;
use Illuminate\Console\Command;
use Laravel\Pennant\Feature;

class FeatureEnableCommand extends Command
{
    use ResolvesFeatures;

    protected $signature = 'feature:enable {feature : The feature class name (e.g., CashflowFeature)} {target : User email or "all" for everyone}';

    protected $description = 'Enable a feature for a specific user or all users';

    public function handle(): int
    {
        $featureName = $this->argument('feature');
        $target = $this->argument('target');

        $featureClass = $this->resolveFeatureClass($featureName);

        if (! $featureClass) {
            $this->error("Feature '{$featureName}' not found.");
            $this->line('Available features: '.$this->getAvailableFeatures());

            return self::FAILURE;
        }

        if ($target === 'all') {
            Feature::activateForEveryone($featureClass);
            $this->info("Feature '{$featureName}' enabled for all users.");

            return self::SUCCESS;
        }

        $user = User::where('email', $target)->first();

        if (! $user) {
            $this->error("User with email '{$target}' not found.");

            return self::FAILURE;
        }

        Feature::for($user)->activate($featureClass);
        $this->info("Feature '{$featureName}' enabled for user '{$user->email}'.");

        return self::SUCCESS;
    }
}
