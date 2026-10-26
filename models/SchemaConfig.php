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
        if (\is_array($saved)) unset($saved['hours']); // Opening hours now come from the company's Location settings

        self::$config = \array_replace_recursive(self::$config, \is_array($saved) ? $saved : []);

        return self::$config;
    }

    /**
     * What the site already knows, used wherever Schema's own field is empty: the site name, the
     * Branding company phone and email, the company's Location opening hours, and the uploaded logo. A value typed into Schema always wins.
     *
     * @param array $site  the theme's $site (name, logoDark, logoLight)
     * @param array $company  Branding's company details (phone, email)
     * @param array $socialLinks  the Social Links service's config as core passes it to theme blocks (empty when
     *   that service is not installed): ['links' => network => URL]
     */
    public static function inherited(array $site, array $company, array $socialLinks = []): array
    {
        $profileLinks = \array_values(\array_filter(\array_map('trim', (array) ($socialLinks['links'] ?? []))));

        return [
            'name' => \trim((string) ($site['name'] ?? '')),
            'phone' => \trim((string) ($company['phone'] ?? '')),
            'email' => \trim((string) ($company['email'] ?? '')),
            'logo' => (string) ($site['logoDark'] ?? $site['logoLight'] ?? ''),
            'hours' => self::companyHours($company['openingHours'] ?? null),
            'sameAs' => $profileLinks,
        ];
    }

    /**
     * The days ticked as open in Company > Location (config company.openingHours, an object once decoded), in DAYS order.
     * Empty when none is ticked, so nothing is printed.
     */
    private static function companyHours(mixed $saved): array
    {
        $savedDays = \json_decode(\json_encode($saved) ?: '[]', associative: true);
        $savedDays = \is_array($savedDays) ? $savedDays : [];

        $hours = [];
        foreach (self::DAYS as $day) {
            $row = $savedDays[$day] ?? null;
            $isOpen = \is_array($row) && !($row['closed'] ?? true);
            if ($isOpen) $hours[$day] = ['closed' => false, 'opens' => (string) $row['opens'], 'closes' => (string) $row['closes']];
        }

        return $hours;
    }

    public static function withFallbacks(array $config, array $inherited): array
    {
        foreach ($inherited as $field => $value) {
            $current = $config[$field] ?? '';
            $isEmpty = \is_array($current) ? !$current : \trim((string) $current) === '';
            if ($isEmpty) $config[$field] = $value;
        }

        return $config;
    }

    public static function clearCache(): void
    {
        self::$config = null;
    }

    public static function defaults(): array
    {
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
            'sameAs' => [],
        ];
    }
}
