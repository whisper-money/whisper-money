<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesFeatures;
use App\Models\User;
use Illuminate\Console\Command;
use Laravel\Pennant\Feature;

class FeatureEnableCommand extends Command
{
    use ResolvesFeatures;

    protected $signature = 'feature:enable {feature : The feature name (class name or string-based feature)} {target : User email, "all" for everyone, or a percentage like "25%" for a random rollout}';

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

        if (preg_match('/^(\d{1,3})%$/', $target, $matches)) {
            return $this->enableForPercentage($featureClass, $featureName, (int) $matches[1]);
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

    private function enableForPercentage(string $featureClass, string $featureName, int $percentage): int
    {
        if ($percentage < 1 || $percentage > 100) {
            $this->error('Percentage must be between 1 and 100.');

            return self::FAILURE;
        }

        $count = (int) ceil(User::count() * $percentage / 100);

        User::inRandomOrder()
            ->take($count)
            ->each(fn (User $user) => Feature::for($user)->activate($featureClass));

        $this->info("Feature '{$featureName}' enabled for {$count} users (~{$percentage}% of current users).");

        return self::SUCCESS;
    }
}
