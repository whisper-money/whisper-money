<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EncryptionController extends Controller
{
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
