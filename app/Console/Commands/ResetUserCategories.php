<?php

namespace App\Console\Commands;

use App\Actions\CreateDefaultCategories;
use App\Models\User;
use Illuminate\Console\Command;

class ResetUserCategories extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'categories:reset {user : The ID or email of the user}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete all categories for a user and create the default ones';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $userIdentifier = $this->argument('user');

        $user = filter_var($userIdentifier, FILTER_VALIDATE_EMAIL)
            ? User::where('email', $userIdentifier)->first()
            : User::find($userIdentifier);

        if (! $user) {
            $this->error("User not found: {$userIdentifier}");

            return self::FAILURE;
        }

        $this->info("Resetting categories for user: {$user->name} ({$user->email})");

        $categoriesCount = $user->categories()->count();

        if ($categoriesCount > 0) {
            $user->categories()->delete();
            $this->info("Deleted {$categoriesCount} existing categories.");
        } else {
            $this->info('No existing categories found.');
        }

        $createDefaultCategories = new CreateDefaultCategories;
        $createDefaultCategories->handle($user);

        $newCategoriesCount = $user->categories()->count();
        $this->info("Created {$newCategoriesCount} default categories.");

        $this->newLine();
        $this->info('✓ Categories reset successfully!');

        return self::SUCCESS;
    }
}
