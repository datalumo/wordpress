<?php

use Datalumo\Wp\Support\Assets;

if (! defined('DATALUMO_VERSION')) {
    define('DATALUMO_VERSION', '0.2.0');
}

it('uses a timestamp when the site environment is local', function () {
    putenv('WP_ENVIRONMENT_TYPE=local');

    try {
        expect(Assets::version())->toMatch('/^\d+$/')
            ->and((int) Assets::version())->toBeGreaterThanOrEqual(time() - 1);
    } finally {
        putenv('WP_ENVIRONMENT_TYPE');
    }
});

it('uses the plugin version outside local', function () {
    putenv('WP_ENVIRONMENT_TYPE=production');

    try {
        expect(Assets::version())->toBe(DATALUMO_VERSION);
    } finally {
        putenv('WP_ENVIRONMENT_TYPE');
    }
});
