<?php

namespace App\Http\Controllers\OpenBanking;

use App\Contracts\BankingProviderInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\OpenBanking\MapConnectionAccountRequest;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\Account;
use App\Models\Bank;
use App\Models\BankingConnection;
use App\Services\AccountUserCurrencyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class ConnectionAccountController extends Controller
{
    public function __construct(private BankingProviderInterface $provider) {}

    public function index(Request $request, BankingConnection $connection): Response
    {
        $this->authorizeConnection($connection);

        $user = $request->user();

        return Inertia::render('open-banking/manage-accounts', [
            'connection' => $connection,
            'syncedAccounts' => $connection->accounts()->with('bank')->get(),
            'availableAccounts' => $user->accounts()
                ->whereNull('banking_connection_id')
                ->with('bank')
                ->get(),
            'discoveredAccounts' => $request->boolean('refresh')
                ? $this->discoverAccounts($connection)
                : null,
        ]);
    }

    public function map(MapConnectionAccountRequest $request, BankingConnection $connection, AccountUserCurrencyService $accountUserCurrencyService): RedirectResponse
    {
        $validated = $request->validated();
        $uid = $validated['bank_account_uid'];

        $bank = Bank::firstOrCreate(
            ['name' => $connection->aspsp_name, 'user_id' => null],
            ['name' => $connection->aspsp_name, 'logo' => $connection->aspsp_logo],
        );

        $currentHolder = $connection->accounts()->where('external_account_id', $uid)->first();

        $iban = $validated['iban'] ?? null;
        if ($currentHolder) {
            $iban = $currentHolder->iban;
        }

        $target = $validated['action'] === 'link'
            ? $connection->user->accounts()->find($validated['existing_account_id'])
            : null;

        if ($validated['action'] === 'link') {
            abort_if($target === null, 404);
            abort_unless($target->type->canSyncBankTransactions(), 422);
        }

        if ($currentHolder && (! $target || $currentHolder->isNot($target))) {
            $currentHolder->update([
                'banking_connection_id' => null,
                'external_account_id' => null,
            ]);
        }

        if ($validated['action'] === 'create') {
            $account = $connection->user->accounts()->create([
                'name' => $validated['name'] ?? $iban ?? $connection->aspsp_name.' Account',
                'name_iv' => null,
                'encrypted' => false,
                'bank_id' => $bank->id,
                'currency_code' => $validated['currency'] ?? 'EUR',
                'type' => $connection->provider->defaultAccountType()->value,
                'banking_connection_id' => $connection->id,
                'external_account_id' => $uid,
                'iban' => $iban,
            ]);
        } else {
            $account = $target;
            $account->update([
                'banking_connection_id' => $connection->id,
                'external_account_id' => $uid,
                'iban' => $iban ?? $account->iban,
                'bank_id' => $bank->id,
                'linked_at' => now(),
            ]);
        }

        $accountUserCurrencyService->syncFromFirstAccount($account);

        SyncBankingConnectionJob::dispatch($connection);

        return back()->with('success', __('Account synced. Transactions will be updated shortly.'));
    }

    public function unlink(BankingConnection $connection, Account $account): RedirectResponse
    {
        $this->authorizeConnection($connection);

        if ($account->banking_connection_id !== $connection->id) {
            abort(404);
        }

        $account->update([
            'banking_connection_id' => null,
            'external_account_id' => null,
        ]);

        return back()->with('success', __('Account is no longer syncing. It is now a manual account.'));
    }

    private function authorizeConnection(BankingConnection $connection): void
    {
        if ($connection->user_id !== auth()->id()) {
            abort(403);
        }
    }

    /**
     * Fetch the bank accounts the consent covers that are not yet synced.
     *
     * The session only returns account uids, so we enrich the unknown ones with a
     * details call. Already-synced uids are skipped — they live in syncedAccounts.
     *
     * @return array<int, array{uid: string, name: string|null, currency: string|null, iban: string|null}>
     */
    private function discoverAccounts(BankingConnection $connection): array
    {
        if (! $connection->isEnableBanking() || ! $connection->isActive() || ! $connection->session_id) {
            return [];
        }

        try {
            $session = $this->provider->getSession($connection->session_id);
        } catch (\Throwable $e) {
            Log::warning('Failed to refresh EnableBanking session accounts', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $knownUids = $connection->accounts()->pluck('external_account_id')->filter()->all();

        $uids = collect($session['accounts_data'] ?? $session['accounts'])
            ->map(fn ($account) => is_array($account) ? ($account['uid'] ?? null) : $account)
            ->filter()
            ->reject(fn (string $uid) => in_array($uid, $knownUids, true))
            ->unique()
            ->values();

        return $uids->map(function (string $uid): ?array {
            try {
                $details = $this->provider->getAccount($uid);
            } catch (\Throwable $e) {
                return null;
            }

            return [
                'uid' => $uid,
                'name' => $details['name'] ?? $details['account_id']['iban'] ?? null,
                'currency' => $details['currency'],
                'iban' => $details['account_id']['iban'] ?? null,
            ];
        })->filter()->values()->all();
    }
}
