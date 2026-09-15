<?php

namespace App\Console\Commands;

use App\Jobs\SendUpdateEmailJob;
use App\Models\User;
use App\Support\PriceTiers;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Laravel\Cashier\Cashier;

class SendUpdateEmailCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email:update
                            {view : The view name (e.g., "jan-2026-updates")}
                            {identifier? : The tracking identifier (defaults to view name)}
                            {--subject= : Custom email subject (default: "Update from Whisper Money")}
                            {--audience=all : Who to send to: all, unsubscribed, cancelling-low-price}
                            {--per-day=50000 : How many emails to queue per day (SES allows 50,000)}
                            {--exclude-demo : Exclude the shared demo and press accounts}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send update emails to all users using a markdown view template';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $viewName = $this->argument('view');
        $identifier = $this->argument('identifier') ?? $viewName;
        $subject = $this->option('subject') ?? 'Update from Whisper Money';
        $perDay = max(1, (int) $this->option('per-day'));

        if ($this->option('force')) {
            $identifier = $identifier.'_force_'.time();
        }

        $viewPath = resource_path("views/mail/updates/{$viewName}.blade.php");

        if (! File::exists($viewPath)) {
            $this->error("View file not found: {$viewPath}");
            $this->info("Please create the view at: resources/views/mail/updates/{$viewName}.blade.php");

            return self::FAILURE;
        }

        $users = $this->audience();

        if ($users === null) {
            return self::FAILURE;
        }

        if ($users->isEmpty()) {
            $this->info('No users found in the database.');

            return self::SUCCESS;
        }

        $this->info("Found {$users->count()} user(s).");

        if (! $this->option('force')) {
            if (! $this->confirm("About to send '{$identifier}' email to {$users->count()} user(s). Continue?", true)) {
                $this->info('Cancelled.');

                return self::SUCCESS;
            }
        }

        $this->info('Queueing update emails...');
        $this->info("Rate limit: {$perDay} emails per day");

        $queued = $this->queue($users, $viewName, $identifier, $subject, $perDay);

        $this->info("Successfully queued {$queued} update email(s) to the 'emails' queue!");

        $days = intdiv($queued - 1, $perDay) + 1;

        if ($days > 1) {
            $this->info("Emails will be sent over {$days} day(s) ({$perDay} emails per day)");
        }

        return self::SUCCESS;
    }

    /**
     * Queue one job per user, in batches of `$perDay` spread a day apart. The
     * default batch is the SES daily quota, so an ordinary send goes out in one
     * go and the `emails` rate limiter is what paces it; `--per-day` is there
     * for a campaign that wants to be slower than that.
     *
     * @param  Collection<int, User>  $users
     */
    private function queue(Collection $users, string $viewName, string $identifier, string $subject, int $perDay): int
    {
        $progressBar = $this->output->createProgressBar($users->count());
        $progressBar->start();

        $queued = 0;
        foreach ($users as $index => $user) {
            SendUpdateEmailJob::dispatch($user, $viewName, $identifier, $subject)
                ->delay(now()->addDays(intdiv($index, $perDay)));

            $queued++;
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        return $queued;
    }

    /**
     * The users the chosen audience covers, or null when it cannot be resolved
     * safely — the caller turns that into a failure rather than mailing a list
     * nobody asked for.
     *
     * @return Collection<int, User>|null
     */
    private function audience(): ?Collection
    {
        $users = match ($this->option('audience')) {
            'all' => User::query()->get(),
            'unsubscribed' => $this->usersWithoutSubscriptionOrTrial(),
            'cancelling-low-price' => $this->usersCancellingOnTheLowPrice(),
            default => $this->refuse("Unknown --audience '{$this->option('audience')}'. Use all, unsubscribed or cancelling-low-price."),
        };

        if ($users === null) {
            return null;
        }

        if ($this->option('exclude-demo')) {
            $users = $users->reject(fn (User $user) => in_array($user->email, User::sharedAccountEmails(), true));
        }

        // Re-key, so the per-day batching counts the users actually being sent to.
        return $users->values();
    }

    /**
     * Everyone with nothing Stripe can still collect on and no trial running.
     *
     * `subscribed()` on top of it because `hasActiveSubscriptionOrTrial()`
     * writes off a subscription the moment it is cancelled, while it is still
     * running until `ends_at`: SubscriptionController::index() bounces those
     * users off /subscribe to the dashboard, so an email inviting them to
     * subscribe would end in a dead CTA. They are the `cancelling-low-price`
     * audience instead, which is the email that fits them.
     *
     * @return Collection<int, User>|null
     */
    private function usersWithoutSubscriptionOrTrial(): ?Collection
    {
        if (! config('subscriptions.enabled')) {
            return $this->refuse('Subscriptions are disabled, so every user reads as unsubscribed. Refusing to send.');
        }

        return User::query()
            ->with('subscriptions')
            ->get()
            ->reject(fn (User $user) => $user->hasActiveSubscriptionOrTrial() || $user->subscribed('default'));
    }

    /**
     * Users who cancelled but are still inside their period, on a price that is
     * not the high tier's.
     *
     * @return Collection<int, User>|null
     */
    private function usersCancellingOnTheLowPrice(): ?Collection
    {
        $highPriceIds = $this->highTierPriceIds();

        if ($highPriceIds === null) {
            return null;
        }

        return User::query()
            ->whereHas('subscriptions', fn (Builder $query) => $query
                ->whereNotNull('ends_at')
                ->where('ends_at', '>', now())
                ->whereNotIn('stripe_price', $highPriceIds))
            ->get();
    }

    /**
     * The high tier's Stripe price IDs, resolved from its lookup keys through
     * the same `stripe_price_id:` cache checkout uses. Resolved rather than
     * hardcoded because the IDs differ per Stripe account, and refused rather
     * than half-resolved because `whereNotIn` on a short list would mail the
     * high-price cohort an email about losing a price they never had.
     *
     * @return list<string>|null
     */
    private function highTierPriceIds(): ?array
    {
        $keys = array_column(PriceTiers::plansFor('high'), 'stripe_lookup_key');

        $ids = array_filter(array_map(
            fn (string $key) => Cache::remember(
                "stripe_price_id:{$key}",
                now()->addHour(),
                fn () => Cashier::stripe()->prices->all(['lookup_keys' => [$key], 'limit' => 1])->data[0]->id ?? null,
            ),
            $keys,
        ));

        if (count($ids) !== count($keys)) {
            return $this->refuse('Could not resolve every high-tier Stripe price from its lookup key ('.implode(', ', $keys).'). Refusing to send.');
        }

        return array_values($ids);
    }

    private function refuse(string $message): null
    {
        $this->error($message);

        return null;
    }
}
