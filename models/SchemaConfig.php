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
        if (\is_array($saved)) unset($saved['hours'], $saved['address'], $saved['geo']); // Opening hours, the address and coordinates now come from the company's Location settings

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
            'address' => self::companyAddress($company['location'] ?? null),
            'geo' => self::companyGeo($company['location'] ?? null),
            'sameAs' => $profileLinks,
        ];
    }

    /** The address fields from Company > Location, or [] when street, town, county and postcode are all empty. */
    public static function companyAddress(mixed $saved): array
    {
        $fields = self::companyLocation($saved);
        $detail = \trim($fields['street'] . $fields['locality'] . $fields['region'] . $fields['postcode']);
        if ($detail === '') return [];

        $country = $fields['country'] !== '' ? $fields['country'] : 'GB';
        return ['street' => $fields['street'], 'locality' => $fields['locality'], 'region' => $fields['region'], 'postcode' => $fields['postcode'], 'country' => $country];
    }

    /** Coordinates typed on Company > Location, or [] unless both are numbers. */
    public static function companyGeo(mixed $saved): array
    {
        $fields = self::companyLocation($saved);
        $bothNumeric = \is_numeric($fields['latitude']) && \is_numeric($fields['longitude']);

        return $bothNumeric ? ['latitude' => $fields['latitude'], 'longitude' => $fields['longitude']] : [];
    }

    /** @return array<string, string> */
    private static function companyLocation(mixed $saved): array
    {
        $decoded = \json_decode(\json_encode($saved) ?: '[]', associative: true);
        $decoded = \is_array($decoded) ? $decoded : [];

        $fields = [];
        foreach (['street', 'locality', 'region', 'postcode', 'country', 'latitude', 'longitude'] as $field) {
            $fields[$field] = \trim((string) ($decoded[$field] ?? ''));
        }

        return $fields;
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

    /** Fields the company's Location settings own: they replace whatever Schema holds whenever Location has a value. */
    private const COMPANY_OWNED = ['hours', 'address', 'geo'];

    public static function withFallbacks(array $config, array $inherited): array
    {
        foreach ($inherited as $field => $value) {
            $current = $config[$field] ?? '';
            $isEmpty = \is_array($current) ? !$current : \trim((string) $current) === '';
            $companyOwned = \in_array($field, self::COMPANY_OWNED, true) && $value;
            if ($isEmpty || $companyOwned) $config[$field] = $value;
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
            'sameAs' => [],
        ];
    }
}
