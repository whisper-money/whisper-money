<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreBankRequest;
use App\Models\Bank;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BankController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Bank::query()->availableForUser($request->user());

        $search = trim((string) $request->input('search', ''));

        $query->when($search !== '', function (Builder $query) use ($search) {
            $query->where('name', 'like', "%{$search}%")
                ->orderByRaw(
                    'CASE WHEN name = ? THEN 0 WHEN name LIKE ? THEN 1 ELSE 2 END',
                    [$search, "{$search}%"]
                );
        });

        $banks = $query->orderBy('name')->get();

        return response()->json(['data' => $banks]);
    }

    public function store(StoreBankRequest $request): JsonResponse
    {
        $data = [
            'name' => $request->validated('name'),
            'user_id' => auth()->id(),
        ];

        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            $path = $file->store('banks/logos', 'public');
            $data['logo'] = Storage::disk('public')->url($path);
        }

        $bank = Bank::query()->create($data);

        return response()->json($bank);
    }
}
