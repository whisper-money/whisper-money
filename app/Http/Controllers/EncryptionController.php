<?php

namespace App\Http\Controllers;

use App\Http\Requests\SetupEncryptionRequest;
use App\Models\EncryptedMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EncryptionController extends Controller
{
    public function setup(SetupEncryptionRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->update([
            'encryption_salt' => $request->validated('salt'),
        ]);

        EncryptedMessage::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'encrypted_content' => $request->validated('encrypted_content'),
                'iv' => $request->validated('iv'),
            ]
        );

        return response()->json([
            'message' => 'Encryption setup completed successfully',
        ]);
    }

    public function getMessage(Request $request): JsonResponse
    {
        $user = $request->user();

        $message = $user->encryptedMessage;

        if (! $message) {
            return response()->json([
                'message' => 'No encrypted message found',
            ], 404);
        }

        return response()->json([
            'encrypted_content' => $message->encrypted_content,
            'iv' => $message->iv,
            'salt' => $user->encryption_salt,
        ]);
    }
}
