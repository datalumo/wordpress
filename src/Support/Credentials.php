<?php

namespace Datalumo\Wp\Support;

class Credentials
{
    public static function looksLikeWidgetKey(string $value): bool
    {
        $value = trim($value);

        if ($value === '' || str_starts_with($value, 'dl_')) {
            return false;
        }

        $parts = explode('/', $value, 2);

        return count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '';
    }

    public static function looksLikeSecret(string $value): bool
    {
        return str_starts_with(trim($value), 'dl_');
    }
}
