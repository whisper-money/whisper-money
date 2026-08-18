<?php

namespace App\Console\Commands\Concerns;

use App\Enums\BankingProvider;
use App\Enums\DripEmailType;
use App\Models\BankingConnection;
use App\Models\User;
use App\Models\UserMailLog;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Shared machinery for the `banking:notify-*` commands. Each one is run by hand
 * once a bank-side problem is confirmed, and they all do the same four things:
 * resolve an Enable Banking bank from an operator-typed name, let the operator
 * review the recipients, email one message per user, and never mail the same
 * user twice for the same bank.
 *
 * A bank is identified by `aspsp_name` + `aspsp_country`, which is also how
 * Enable Banking identifies an ASPSP: `banking_connections` has no bank_id, and
 * the `banks` table is per-user, so a bank UUID means nothing outside the one
 * account it belongs to.
 *
 * A command using this must declare all four shared options in its signature —
 * `--country`, `--dry-run`, `--force` and `--resend` — because the helpers here
 * read them.
 *
 * @mixin Command
 */
trait NotifiesBankUsers
{
    /**
     * The withCount alias the recipient tables read.
     */
    protected const MATCHED_CONNECTIONS = 'matched_connections_count';

    /**
     * The `--country` option, normalized to the casing the column uses.
     */
    protected function countryOption(): ?string
    {
        return $this->option('country') ? Str::upper((string) $this->option('country')) : null;
    }

    /**
     * Every Enable Banking connection to the bank the operator named.
     */
    protected function matchesBank(string $aspsp, ?string $country): Closure
    {
        return fn (Builder $query) => $query
            ->where('provider', BankingProvider::EnableBanking)
            ->where('aspsp_name', $aspsp)
            ->when($country, fn (Builder $query) => $query->where('aspsp_country', $country));
    }

    /**
     * The distinct name/country pairs a predicate matches, which is how a bank
     * gets resolved from whatever the operator typed.
     *
     * @return Collection<int, BankingConnection>
     */
    protected function affectedBanks(Closure $matches): Collection
    {
        return BankingConnection::query()
            ->tap($matches)
            ->select('aspsp_name', 'aspsp_country')
            ->distinct()
            ->orderBy('aspsp_name')
            ->get();
    }

    /**
     * The one country in play, or null when the operator has to disambiguate.
     *
     * A bank name is not unique: Revolut alone spans five countries here. Since
     * an Enable Banking problem is per bank *and* country, guessing would mean
     * emailing people whose bank works fine.
     *
     * @param  Collection<int, BankingConnection>  $banks
     */
    protected function resolveCountry(Collection $banks, string $aspsp, ?string $country): ?string
    {
        $countries = $banks->pluck('aspsp_country')->unique();

        if ($country === null && $countries->count() > 1) {
            $this->error("{$aspsp} is affected in ".$countries->sort()->implode(', ').'.');
            $this->line('An Enable Banking bank is identified by its country too, so re-run with --country=XX.');

            return null;
        }

        return $country ?? (string) $countries->first();
    }

    /**
     * The ledger key for one bank's notice, stable across re-runs.
     */
    protected function bankIdentifier(string $bankName, string $country): string
    {
        return Str::slug("{$bankName}-{$country}");
    }

    /**
     * The users to email: one row each however many connections they have to the
     * bank, minus anyone already told about it.
     *
     * @return Collection<int, User>
     */
    protected function recipients(Closure $matches, DripEmailType $type, string $identifier): Collection
    {
        return User::query()
            ->whereHas('bankingConnections', $matches)
            ->withCount(['bankingConnections as '.self::MATCHED_CONNECTIONS => $matches])
            ->unless($this->option('resend'), fn (Builder $query) => $query->whereDoesntHave(
                'mailLogs',
                fn (Builder $query) => $query
                    ->where('email_type', $type)
                    ->where('email_identifier', $identifier),
            ))
            ->orderBy('email')
            ->get()
            ->filter->canReceiveEmails();
    }

    /**
     * Whether real email should go out, with the reason already reported when
     * it should not: a dry run, or a declined confirmation.
     */
    protected function shouldSend(string $question): bool
    {
        if ($this->option('dry-run')) {
            $this->info('[dry-run] No emails sent.');

            return false;
        }

        // --force is blanket consent for a first send. --resend is the one
        // combination that puts a second copy of the same message in a real
        // inbox, so it is always confirmed by hand.
        if ($this->option('force') && ! $this->option('resend')) {
            return true;
        }

        if (! $this->confirm($question, false)) {
            $this->info('Cancelled.');

            return false;
        }

        return true;
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  Closure(User): Mailable  $mailable
     */
    protected function sendAndLog(Collection $users, DripEmailType $type, string $identifier, Closure $mailable): void
    {
        foreach ($users as $user) {
            Mail::to($user)->send($mailable($user));

            // Logged when queued rather than when delivered: the ledger's job is
            // to make a second run skip whoever already got the message.
            UserMailLog::updateOrCreate([
                'user_id' => $user->id,
                'email_type' => $type,
                'email_identifier' => $identifier,
            ], ['sent_at' => now()]);
        }

        $this->info("Queued {$users->count()} email(s) to the 'emails' queue.");
    }

    /**
     * Explain a run that resolved nobody, so a mistyped or renamed bank does not
     * read as "no one is affected". Soft-deleted rows count as candidates here:
     * a bank whose every attempt failed has nothing but soft-deleted rows.
     */
    protected function reportUnknownBank(string $aspsp, ?string $country): void
    {
        $this->info('No Enable Banking connection to '.$aspsp.($country ? " ({$country})" : '').'.');

        // The usual mistake is the right name with the wrong country, so say
        // where that bank does exist before offering other names.
        $countries = BankingConnection::withTrashed()
            ->where('provider', BankingProvider::EnableBanking)
            ->where('aspsp_name', $aspsp)
            ->distinct()
            ->orderBy('aspsp_country')
            ->pluck('aspsp_country');

        if ($countries->isNotEmpty()) {
            $this->line("{$aspsp} exists in ".$countries->implode(', ').'.');

            return;
        }

        $similar = BankingConnection::withTrashed()
            ->where('provider', BankingProvider::EnableBanking)
            ->where('aspsp_name', 'like', '%'.$aspsp.'%')
            ->where('aspsp_name', '!=', $aspsp)
            ->distinct()
            ->orderBy('aspsp_name')
            ->pluck('aspsp_name');

        if ($similar->isNotEmpty()) {
            $this->line('Did you mean: '.$similar->implode(', ').'?');
        }
    }
}
