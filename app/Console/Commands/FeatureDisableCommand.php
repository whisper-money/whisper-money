<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesFeatures;
use App\Models\User;
use Illuminate\Console\Command;
use Laravel\Pennant\Feature;

class FeatureDisableCommand extends Command
{
    use ResolvesFeatures;

    protected $signature = 'feature:disable {feature : The feature name (class name or string-based feature)} {target : User email or "all" for everyone}';

    protected $description = 'Disable a feature for a specific user or all users';

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
            Feature::deactivateForEveryone($featureClass);
            $this->info("Feature '{$featureName}' disabled for all users.");

            return self::SUCCESS;
        }

        $user = User::where('email', $target)->first();

        if (! $user) {
            $this->error("User with email '{$target}' not found.");

            return self::FAILURE;
        }

        Feature::for($user)->deactivate($featureClass);
        $this->info("Feature '{$featureName}' disabled for user '{$user->email}'.");

        return self::SUCCESS;
    }
}
