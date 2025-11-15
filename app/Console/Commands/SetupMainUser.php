<?php

namespace App\Console\Commands;

use App\Models\AutomationRule;
use App\Models\Category;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class SetupMainUser extends Command
{
    protected $signature = 'user:setup-main {user? : The UUID or email of the user}';

    protected $description = 'Import user data from JSON files in the .local directory';

    public function handle(): int
    {
        $this->info('Setting up main user data...');
        $this->newLine();

        $userIdentifier = $this->argument('user') ?? $this->ask('Enter user UUID or email');

        $user = $this->findUser($userIdentifier);

        if (! $user) {
            $this->error("User not found: {$userIdentifier}");

            return self::FAILURE;
        }

        $this->info("Found user: {$user->name} ({$user->email})");
        $this->newLine();

        $localPath = base_path('.local');

        if (! File::isDirectory($localPath)) {
            $this->warn("Directory .local not found at {$localPath}");

            return self::SUCCESS;
        }

        $jsonFiles = File::glob("{$localPath}/*.json");

        if (empty($jsonFiles)) {
            $this->warn('No JSON files found in .local directory');

            return self::SUCCESS;
        }

        $this->info('Found JSON files to import:');
        foreach ($jsonFiles as $file) {
            $this->line('  - '.basename($file));
        }
        $this->newLine();

        $importOrder = ['categories.json', 'automated_rules.json'];

        foreach ($importOrder as $fileName) {
            $filePath = "{$localPath}/{$fileName}";

            if (! File::exists($filePath)) {
                continue;
            }

            $this->info("Processing {$fileName}...");

            $content = File::get($filePath);
            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->error("  ✗ Failed to parse {$fileName}: ".json_last_error_msg());

                continue;
            }

            if (! is_array($data)) {
                $this->error("  ✗ Invalid data format in {$fileName}");

                continue;
            }

            match ($fileName) {
                'categories.json' => $this->importCategories($user, $data),
                'automated_rules.json' => $this->importAutomationRules($user, $data),
                default => $this->warn("  ! Skipping {$fileName} (no importer defined)"),
            };
        }

        foreach ($jsonFiles as $filePath) {
            $fileName = basename($filePath);

            if (in_array($fileName, $importOrder)) {
                continue;
            }

            $this->info("Processing {$fileName}...");

            $content = File::get($filePath);
            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->error("  ✗ Failed to parse {$fileName}: ".json_last_error_msg());

                continue;
            }

            if (! is_array($data)) {
                $this->error("  ✗ Invalid data format in {$fileName}");

                continue;
            }

            $this->warn("  ! Skipping {$fileName} (no importer defined)");
        }

        $this->newLine();
        $this->info('✓ Main user setup completed successfully!');

        return self::SUCCESS;
    }

    protected function importCategories(User $user, array $data): void
    {
        $imported = 0;

        foreach ($data as $categoryData) {
            Category::create([
                'user_id' => $user->id,
                'name' => $categoryData['name'],
                'icon' => $categoryData['icon'],
                'color' => $categoryData['color'],
                'created_at' => $categoryData['created_at'] ?? now(),
                'updated_at' => $categoryData['updated_at'] ?? now(),
            ]);

            $imported++;
        }

        $this->info("  ✓ Imported {$imported} categories");
    }

    protected function importAutomationRules(User $user, array $data): void
    {
        $imported = 0;
        $skipped = 0;

        foreach ($data as $ruleData) {
            $categoryName = $ruleData['category']['name'] ?? null;

            if (! $categoryName) {
                $this->warn("  ! Skipping rule '{$ruleData['title']}' - no category name");
                $skipped++;

                continue;
            }

            $category = Category::where('user_id', $user->id)
                ->where('name', $categoryName)
                ->first();

            if (! $category) {
                $this->warn("  ! Skipping rule '{$ruleData['title']}' - category '{$categoryName}' not found");
                $skipped++;

                continue;
            }

            AutomationRule::create([
                'user_id' => $user->id,
                'title' => $ruleData['title'],
                'priority' => $ruleData['priority'],
                'rules_json' => $ruleData['rules_json'],
                'action_category_id' => $category->id,
                'action_note' => $ruleData['action_note'] ?? null,
                'action_note_iv' => $ruleData['action_note_iv'] ?? null,
                'created_at' => $ruleData['created_at'] ?? now(),
                'updated_at' => $ruleData['updated_at'] ?? now(),
            ]);

            $imported++;
        }

        $this->info("  ✓ Imported {$imported} automation rules".($skipped > 0 ? " ({$skipped} skipped)" : ''));
    }

    protected function findUser(string $identifier): ?User
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $identifier)) {
            return User::find($identifier);
        }

        return User::where('email', $identifier)->first();
    }
}
