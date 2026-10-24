<?php

namespace App\Services;

use App\Models\Bank;
use App\Models\RealEstateDetail;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * A short fingerprint of each list the app shell shares with every page —
 * accounts, categories, banks, labels and automation rules — so the client can
 * keep them between visits and still learn the moment one changes.
 *
 * Each fingerprint folds the row count (trashed rows included) and the latest
 * `updated_at` of every table the serialized list reads from: a create, an
 * edit, a soft delete, a restore or a hard delete each move one of the two,
 * whichever path made it — a dialog, an import, a bank sync, an MCP tool or
 * another device. A rule's labels live on a pivot, which touches the rule.
 *
 * All of it is one query of scalar subselects, each on the `user_id` index.
 */
class UserCollectionVersions
{
    /**
     * The fingerprint of each shared list, keyed by its prop name.
     *
     * @return array{accounts: string, categories: string, banks: string, labels: string, automationRules: string}
     */
    public function for(User $user): array
    {
        $sources = $this->sources($user);

        $query = User::query()->whereKey($user->getKey())->toBase();

        foreach ($sources as $name => $source) {
            $query->selectSub((clone $source)->toBase()->selectRaw('count(*)'), "{$name}_rows")
                ->selectSub((clone $source)->toBase()->selectRaw('max(updated_at)'), "{$name}_updated");
        }

        $row = (array) $query->first();

        $fingerprint = fn (string ...$names): string => hash('xxh3', implode('|', array_map(
            fn (string $name): string => $row["{$name}_rows"].'@'.$row["{$name}_updated"],
            $names,
        )));

        return [
            'accounts' => $fingerprint('accounts', 'account_banks', 'real_estate_details'),
            'categories' => $fingerprint('categories'),
            'banks' => $fingerprint('banks'),
            'labels' => $fingerprint('labels'),
            // A rule is serialized with its category and its labels.
            'automationRules' => $fingerprint('automation_rules', 'categories', 'labels'),
        ];
    }

    /**
     * Every table a shared list is read from, scoped to the user.
     *
     * @return array<string, Builder<*>|Relation<*, *, *>>
     */
    private function sources(User $user): array
    {
        $accountIds = $user->accounts()->withTrashed()->select('id');

        return [
            'accounts' => $user->accounts()->withTrashed(),
            // Accounts carry their bank, which may be a shared one rather than
            // one of the user's own.
            'account_banks' => Bank::withTrashed()->whereIn('id', $user->accounts()->withTrashed()->select('bank_id')),
            // ...and the loan a property is linked to.
            'real_estate_details' => RealEstateDetail::query()->whereIn('account_id', $accountIds),
            'categories' => $user->categories()->withTrashed(),
            'banks' => $user->banks()->withTrashed(),
            'labels' => $user->labels()->withTrashed(),
            'automation_rules' => $user->automationRules()->withTrashed(),
        ];
    }
}
