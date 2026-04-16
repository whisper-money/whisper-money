<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateTimezoneRequest;
use Illuminate\Http\Response;

class TimezoneController extends Controller
{
    public function update(UpdateTimezoneRequest $request): Response
    {
        $user = $request->user();

        if ($user->timezone === null) {
            $user->update(['timezone' => $request->validated('timezone')]);
        }

        return response()->noContent();
    }
}
