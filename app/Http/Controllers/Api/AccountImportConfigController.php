<?php

namespace App\Http\Controllers\Api;

use App\Enums\ImportConfigType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateAccountImportConfigRequest;
use App\Models\Account;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountImportConfigController extends Controller
{
    use AuthorizesRequests;

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
