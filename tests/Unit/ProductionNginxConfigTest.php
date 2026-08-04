<?php

$params = file_get_contents(__DIR__.'/../../docker/nginx/fastcgi_params');
$dockerfile = file_get_contents(__DIR__.'/../../Dockerfile.production');

it('passes a port preserving HTTP_HOST to php-fpm', function () use ($params) {
    expect($params)->toMatch('/^fastcgi_param\s+HTTP_HOST\s+\$http_host;$/m');
});

it('ships the fastcgi params instead of relying on the ones from the base image', function () use ($dockerfile) {
    expect($dockerfile)->toContain('COPY docker/nginx/fastcgi_params /etc/nginx/fastcgi_params');
});
