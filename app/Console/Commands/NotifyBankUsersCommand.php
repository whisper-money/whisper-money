<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\NotifiesBankUsers;
use App\Enums\DripEmailType;
use App\Mail\BankNoticeEmail;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Emails everyone connected to one Enable Banking bank a notice written by hand
 * as a Markdown file, for the one-off situations that do not deserve a command
 * and a committed view of their own.
 *
 * Its `banking:notify-*` siblings each carry the copy for one recurring
 * situation. Here the copy is a file the operator writes and uploads, so it is
 * read and passed to the mailable as text: the queue worker that sends it is
 * not guaranteed to have the file, and the file can change in between.
 *
 * One file per locale, `<base>.<locale>.md`. `<base>.en.md` must exist and is
 * what anyone whose language has no file of its own receives. Each file opens
 * with an `# H1`, which is used as the subject, and may use `:name` where the
 * recipient's name belongs.
 *
 * Everyone with a live connection to the bank is notified, whatever state that
 * connection is in. Unlike the outage notice, this one makes no claim about the
 * bank answering or not, so there is nothing to narrow the recipients to.
 *
 * Also unlike the outage notice, a bank present in several countries is notified
 * in all of them by default: an operator-written notice is usually about the bank
 * rather than about one country's connector, and `--country` is there when it
 * is not. Nothing double-sends either way, because the ledger is per user.
 */
class NotifyBankUsersCommand extends Command implements Isolatable
{
    use NotifiesBankUsers;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'banking:notify-users
                            {aspsp : The bank name as stored on the connection, e.g. "Trade Republic"}
                            {notice : Path to the notice, with or without its locale suffix, e.g. resources/notices/my-notice}
                            {--country= : ISO country code, to notify one country of a bank present in several (e.g. ES)}
                            {--dry-run : List the recipients without sending anything}
                            {--force : Skip the confirmation prompt}
                            {--resend : Notify users who already got this notice}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Email the users connected to one Enable Banking bank a notice written as a Markdown file';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $base = $this->noticeBase((string) $this->argument('notice'));
        $notices = $this->readNotices($base);

        if ($notices === null) {
            return self::FAILURE;
        }

        $aspsp = (string) $this->argument('aspsp');
        $country = $this->countryOption();
        $banks = $this->affectedBanks($this->matchesBank($aspsp, $country));

        if ($banks->isEmpty()) {
            $this->reportUnknownBank($aspsp, $country);

            return self::SUCCESS;
        }

        // The bank name comes from the data, so the console output and the
        // ledger key agree however the operator typed it.
        $bankName = (string) $banks->first()->aspsp_name;
        $displayName = $bankName.' ('.$banks->pluck('aspsp_country')->sort()->implode(', ').')';
        // The ledger key carries the notice as well as the bank, because the
        // second notice ever sent to a bank must not be suppressed by the first.
        // The country is left out on purpose: the ledger is per user, so a
        // whole-bank run and a --country run cannot overlap.
        $identifier = Str::slug($bankName.'-'.basename($base));

        $users = $this->recipients($this->matchesBank($bankName, $country), DripEmailType::BankNotice, $identifier);

        if ($users->isEmpty()) {
            $this->info("Nobody to notify at {$displayName}: everyone connected already got this notice, or has since deleted their account. Use --resend to send it again.");

            return self::SUCCESS;
        }

        $this->reportScope($users, $notices, $displayName);

        $subject = (string) BankNoticeEmail::subjectOf($notices['en']);

        if (! $this->shouldSend("Send \"{$subject}\" to {$users->count()} user(s) connected to {$displayName}?")) {
            return self::SUCCESS;
        }

        $this->sendAndLog(
            $users,
            DripEmailType::BankNotice,
            $identifier,
            fn (User $user) => new BankNoticeEmail($user, $notices),
        );

        return self::SUCCESS;
    }

    /**
     * The path with any locale suffix dropped, so `my-notice`, `my-notice.en.md`
     * and `my-notice.es.md` all name the same set of files.
     */
    private function noticeBase(string $path): string
    {
        return (string) preg_replace('/\.[A-Za-z]{2}([_-][A-Za-z]{2,4})?\.md$/', '', $path);
    }

    /**
     * The notice as one Markdown body per locale, or null with the reason
     * already reported.
     *
     * @return array<string, string>|null
     */
    private function readNotices(string $base): ?array
    {
        $notices = [];

        foreach (File::glob($base.'.*.md') as $file) {
            $notices[Str::afterLast(basename($file, '.md'), '.')] = (string) File::get($file);
        }

        if (! isset($notices['en'])) {
            $this->error("No English notice at {$base}.en.md.");
            $this->line('Every notice needs one: it is what anyone whose language has no file of its own receives.');

            return null;
        }

        foreach ($notices as $locale => $notice) {
            if (BankNoticeEmail::subjectOf($notice) === null) {
                $this->error("{$base}.{$locale}.md does not start with a heading.");
                $this->line('The first line must be "# Something", which is used as the email subject.');

                return null;
            }
        }

        return $notices;
    }

    /**
     * Show what is about to go out, and to whom, in the recipient's own
     * language: this copy was written outside the codebase, so the operator
     * reviewing it is the only check it gets.
     *
     * @param  Collection<int, User>  $users
     * @param  array<string, string>  $notices
     */
    private function reportScope(Collection $users, array $notices, string $displayName): void
    {
        $this->table(['Locale', 'Subject'], collect($notices)
            ->map(fn (string $notice, string $locale) => [$locale, (string) BankNoticeEmail::subjectOf($notice)])
            ->values()
            ->all());

        $this->table(['Email', 'Connections', 'Reads'], $users->map(fn (User $user) => [
            $user->email,
            (int) $user->getAttribute(self::MATCHED_CONNECTIONS),
            BankNoticeEmail::localeFor($notices, $user->preferredLocale()),
        ])->all());

        $this->info("{$users->count()} user(s) connected to {$displayName} to notify.");
    }
}
