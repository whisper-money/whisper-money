<?php

test('sitemap returns valid xml', function () {
    $response = $this->get('/sitemap.xml');

    $response->assertSuccessful();
    $response->assertHeader('Content-Type', 'application/xml');
    expect($response->content())->toContain('<?xml version="1.0" encoding="UTF-8"?>');
    expect($response->content())->toContain('<urlset');
    expect($response->content())->toContain(config('app.url'));
});

test('sitemap includes homepage', function () {
    $response = $this->get('/sitemap.xml');

    $response->assertSuccessful();
    expect($response->content())->toContain('<loc>'.config('app.url').'</loc>');
});

test('sitemap includes privacy and terms pages', function () {
    $response = $this->get('/sitemap.xml');

    $response->assertSuccessful();
    expect($response->content())->toContain('<loc>'.config('app.url').'/privacy</loc>');
    expect($response->content())->toContain('<loc>'.config('app.url').'/terms</loc>');
});

test('robots txt returns valid content', function () {
    $response = $this->get('/robots.txt');

    $response->assertSuccessful();
    expect($response->headers->get('Content-Type'))->toContain('text/plain');
    expect($response->content())->toContain('User-agent: *');
    expect($response->content())->toContain('Sitemap: '.config('app.url').'/sitemap.xml');
});

test('robots txt disallows protected routes', function () {
    $response = $this->get('/robots.txt');

    $response->assertSuccessful();
    expect($response->content())->toContain('Disallow: /api/');
    expect($response->content())->toContain('Disallow: /dashboard');
});
