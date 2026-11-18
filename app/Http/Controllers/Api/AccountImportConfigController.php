<?php

namespace App\Http\Controllers\Api;

use App\Enums\ImportConfigType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateAccountImportConfigRequest;
use App\Models\Account;
use App\Models\AccountImportConfig;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountImportConfigController extends Controller
{
    use AuthorizesRequests;

    /**
     * Every config of one type across the user's accounts, so an import can
     * find the account a file went to last time without knowing it first.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::enum(ImportConfigType::class)],
        ]);

        $configs = AccountImportConfig::query()
            ->where('type', $validated['type'])
            ->whereHas('account', fn ($query) => $query->where('user_id', $request->user()->id))
            ->get(['account_id', 'config']);

        return response()->json([
            'data' => $configs->map(fn (AccountImportConfig $config): array => [
                'account_id' => $config->account_id,
                'config' => $config->config,
            ])->values(),
        ]);
    }

    public function show(Request $request, Account $account): JsonResponse
    {
        $this->authorize('view', $account);

        $validated = $request->validate([
            'type' => ['required', Rule::enum(ImportConfigType::class)],
        ]);

        $config = $account->importConfigs()
            ->where('type', $validated['type'])
            ->first();

        return response()->json(['data' => $config?->config]);
    }

    public function update(UpdateAccountImportConfigRequest $request, Account $account): JsonResponse
    {
        $this->authorize('update', $account);

        $config = $account->importConfigs()->updateOrCreate(
            ['type' => $request->validated('type')],
            ['config' => $request->validated('config')],
        );

        return response()->json(['data' => $config->config]);
    }
}
