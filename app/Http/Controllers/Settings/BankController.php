<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreBankRequest;
use App\Models\Bank;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BankController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Bank::query()
            ->where(function ($q) {
                $q->whereNull('user_id')
                    ->orWhere('user_id', auth()->id());
            });

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%");
        }

        $banks = $query->orderBy('name')->limit(20)->get();

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
