<?php

use App\Services\Discord\DiscordWebhook;
use Illuminate\Support\Facades\Http;

test('posts content and embeds to the webhook url', function () {
    Http::fake();

    (new DiscordWebhook('https://discord.test/webhook'))
        ->send('hello', [['title' => 'Test embed']]);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://discord.test/webhook'
            && $request['content'] === 'hello'
            && $request['embeds'][0]['title'] === 'Test embed';
    });
});

test('omits empty content and embeds from the payload', function () {
    Http::fake();

    (new DiscordWebhook('https://discord.test/webhook'))->send('only content');

    Http::assertSent(function ($request) {
        return $request['content'] === 'only content'
            && ! isset($request['embeds']);
    });
});

test('does not post when the webhook url is missing', function () {
    Http::fake();

    (new DiscordWebhook(null))->send('hello');

    Http::assertNothingSent();
});
