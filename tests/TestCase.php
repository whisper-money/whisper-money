<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['subscriptions.enabled' => false]);
    }

    /**
     * Generate a valid base64-encoded encryption key for testing.
     * This creates a 32-byte key (AES-256) and encodes it as base64.
     */
    protected function generateTestEncryptionKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    /**
     * Set up encryption key in browser localStorage for testing.
     * This can be called before or after visiting a page.
     * If called after visiting, it will reload the page.
     */
    protected function setupEncryptionKey($page, ?string $key = null, bool $reload = true): void
    {
        $key = $key ?? $this->generateTestEncryptionKey();
        $page->script("localStorage.setItem('encryption_key', ".json_encode($key).')');
        if ($reload) {
            $currentUrl = $page->url();
            $page->navigate($currentUrl)->wait(1);
        }
    }

    /**
     * Visit a page with encryption key already set up.
     */
    protected function visitWithEncryptionKey(string $url, ?string $key = null)
    {
        $key = $key ?? $this->generateTestEncryptionKey();
        $page = visit($url);
        $page->script("localStorage.setItem('encryption_key', ".json_encode($key).')');
        $page->navigate($url)->wait(1);

        return $page;
    }

    /**
     * Get or generate a consistent test encryption key.
     * This ensures the same key is used across the test.
     */
    protected function getTestEncryptionKey(): string
    {
        static $key = null;
        if ($key === null) {
            $key = $this->generateTestEncryptionKey();
        }

        return $key;
    }
}
