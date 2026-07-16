<?php

namespace Datalumo\Wp\Support;

/**
 * Accessor over the plugin's single settings option. Keys use dot notation
 * ("chatbot.widget_key"). The option name is distinct from both legacy
 * Datalumo plugins so all three can coexist installed.
 */
class Options
{
    public const OPTION = 'datalumo_settings';

    public const SYNC_STATE = 'datalumo_sync_state';

    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);

        return is_array($stored) ? $stored : [];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::all();

        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public static function set(string $key, mixed $newValue): void
    {
        $all = self::all();
        $cursor = &$all;
        $segments = explode('.', $key);
        $last = array_pop($segments);

        foreach ($segments as $segment) {
            if (! isset($cursor[$segment]) || ! is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }

        $cursor[$last] = $newValue;

        update_option(self::OPTION, $all);
    }

    public static function merge(array $values): void
    {
        update_option(self::OPTION, array_merge(self::all(), $values));
    }

    /**
     * The Datalumo app URL (no trailing slash). A DATALUMO_API_URL constant
     * (e.g. http://dl.test in development) beats the stored setting. Earlier
     * plugin generations configured the full API URL, so a trailing
     * /api/v1 is tolerated and stripped.
     */
    public static function baseUrl(): string
    {
        $url = defined('DATALUMO_API_URL') && DATALUMO_API_URL
            ? (string) DATALUMO_API_URL
            : (string) self::get('api_url', 'https://datalumo.com');

        return rtrim((string) preg_replace('#/api(/v\d+)?/?$#', '', rtrim($url, '/')), '/');
    }

    public static function isConnected(): bool
    {
        return (string) self::get('api_token') !== ''
            && (string) self::get('organisation.id') !== '';
    }
}
