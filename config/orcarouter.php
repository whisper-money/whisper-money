<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OrcaRouter provider
    |--------------------------------------------------------------------------
    |
    | Named openai-compatible provider instance used when AI_PROVIDER (or a
    | per-feature *_PROVIDER) is set to "orcarouter". OrcaRouter is a gateway
    | that serves frontier models over an OpenAI-compatible endpoint, so it is
    | wired through laravel/ai's openai-compatible driver with its own base URL
    | and key. The default model is the gateway's auto-routed "orcarouter/auto";
    | the per-feature *_MODEL vars still override it.
    |
    */

    'url' => env('ORCAROUTER_URL', 'https://api.orcarouter.ai/v1'),

    'key' => env('ORCAROUTER_API_KEY'),

    'model' => env('ORCAROUTER_MODEL', 'orcarouter/auto'),

];
