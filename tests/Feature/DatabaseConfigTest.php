<?php

use Pdo\Mysql;

/**
 * @return array<string, mixed>
 */
function loadDatabaseConfig(): array
{
    $deprecations = [];

    set_error_handler(function (int $errno, string $message) use (&$deprecations): bool {
        $deprecations[] = $message;

        return true;
    }, E_DEPRECATED | E_USER_DEPRECATED);

    try {
        $config = require config_path('database.php');
    } finally {
        restore_error_handler();
    }

    expect($deprecations)->toBe([]);

    return $config;
}

it('loads the database config without deprecation notices', function () {
    $_SERVER['MYSQL_ATTR_SSL_CA'] = '/etc/ssl/certs/ca.pem';

    try {
        $config = loadDatabaseConfig();
    } finally {
        unset($_SERVER['MYSQL_ATTR_SSL_CA']);
    }

    $sslCaAttribute = PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA;

    foreach (['mysql', 'mariadb', 'prod'] as $connection) {
        expect($config['connections'][$connection]['options'])
            ->toBe([$sslCaAttribute => '/etc/ssl/certs/ca.pem']);
    }
})->skip(! extension_loaded('pdo_mysql'), 'pdo_mysql is not loaded');

it('leaves the mysql options empty when no SSL CA is configured', function () {
    unset($_SERVER['MYSQL_ATTR_SSL_CA'], $_ENV['MYSQL_ATTR_SSL_CA']);
    putenv('MYSQL_ATTR_SSL_CA');

    expect(loadDatabaseConfig()['connections']['mysql']['options'])->toBe([]);
});
