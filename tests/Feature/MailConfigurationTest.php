<?php

use Aws\Ses\SesClient;

test('amazon ses mailer is configured for production email delivery', function () {
    expect(config('mail.mailers.ses.transport'))->toBe('ses')
        ->and(config('services.ses.key'))->toBe(env('AWS_ACCESS_KEY_ID'))
        ->and(config('services.ses.secret'))->toBe(env('AWS_SECRET_ACCESS_KEY'))
        ->and(config('services.ses.region'))->toBe(env('AWS_DEFAULT_REGION', 'us-east-1'))
        ->and(config('services.ses.token'))->toBe(env('AWS_SESSION_TOKEN'))
        ->and(class_exists(SesClient::class))->toBeTrue();
});
