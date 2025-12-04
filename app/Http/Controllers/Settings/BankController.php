<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreBankRequest;
use App\Models\Bank;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class BankController extends Controller
{
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
