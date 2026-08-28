<?php

namespace Datalumo\Wp\Support;

class Assets
{
    public static function version(): string
    {
        $environment = defined('WP_ENVIRONMENT_TYPE')
            ? (string) WP_ENVIRONMENT_TYPE
            : (string) getenv('WP_ENVIRONMENT_TYPE');

        if ($environment === 'local') {
            return (string) time();
        }

        return DATALUMO_VERSION;
    }
}
