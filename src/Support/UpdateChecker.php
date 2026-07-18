<?php

namespace Datalumo\Wp\Support;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * Pulls updates from the public GitHub repo via Plugin Update Checker.
 *
 * Releases: create a GitHub release (or tag). Prefer attaching a zip asset
 * that includes vendor/ so Composer dependencies ship with the update;
 * without an asset PUC falls back to GitHub's source archive.
 */
class UpdateChecker
{
    public static function register(): void
    {
        if (! class_exists(PucFactory::class)) {
            return;
        }

        $checker = PucFactory::buildUpdateChecker(
            'https://github.com/datalumo/wordpress/',
            DATALUMO_FILE,
            'datalumo',
        );

        $api = $checker->getVcsApi();

        if ($api !== null && method_exists($api, 'enableReleaseAssets')) {
            $api->enableReleaseAssets('/\.zip($|[?&#])/i');
        }
    }
}
