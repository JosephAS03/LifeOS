<?php

declare(strict_types=1);

namespace LifeOS\Support;

final class Tables
{
    public static function prefixed(string $suffix): string
    {
        global $wpdb;

        return $wpdb->prefix . 'life_os_' . $suffix;
    }
}

