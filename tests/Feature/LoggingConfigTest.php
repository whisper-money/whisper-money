<?php

test('file log channels are created group-writable', function (string $channel) {
    expect(config("logging.channels.{$channel}.permission"))->toBe(0664);
})->with(['single', 'daily']);
