<?php

declare(strict_types=1);

namespace Osmium\Services\Schema\Models;

/**
 * Loads this service's own settings file (app/config/services/schema.json.php),
 * the same file-based convention as the other services. Missing file or missing
 * name means nothing is printed.
 */
class SchemaConfig
{
    public const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    private static ?array $config = null;
    private static string $configPath = 'app/config/services/schema.json.php';

    public static function get(): array
    {
        $configLoaded = self::$config !== null;
        if ($configLoaded) return self::$config;

        self::$config = self::defaults();

        $configExists = \file_exists(self::$configPath);
        if (!$configExists) return self::$config;

        $content = \file_get_contents(self::$configPath);
        $jsonStart = \strpos(haystack: $content, needle: '{');
        $noJsonFound = $jsonStart === false;
        if ($noJsonFound) return self::$config;

        $decoded = \json_decode(\substr(string: $content, offset: $jsonStart), associative: true);
        $saved = \is_array($decoded) ? ($decoded['schema'] ?? []) : [];

        self::$config = \array_replace_recursive(self::$config, \is_array($saved) ? $saved : []);

        return self::$config;
    }

    public static function clearCache(): void
    {
        self::$config = null;
    }

    public static function defaults(): array
    {
        $hours = [];
        foreach (self::DAYS as $day) {
            $hours[$day] = ['closed' => true, 'opens' => '09:00', 'closes' => '17:00'];
        }

        return [
            'type' => 'Organization',
            'name' => '',
            'legalName' => '',
            'url' => '',
            'logo' => '',
            'description' => '',
            'email' => '',
            'phone' => '',
            'priceRange' => '',
            'address' => ['street' => '', 'locality' => '', 'region' => '', 'postcode' => '', 'country' => 'GB'],
            'geo' => ['latitude' => '', 'longitude' => ''],
            'hours' => $hours,
            'sameAs' => [],
        ];
    }
}
