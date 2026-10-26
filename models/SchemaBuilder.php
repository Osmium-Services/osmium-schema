<?php

declare(strict_types=1);

namespace Osmium\Services\Schema\Models;

/**
 * Builds the site-wide JSON-LD: one @graph holding the Organization (or LocalBusiness
 * subtype) and the WebSite, each with a fixed @id so other services can reference them
 * ({"@id": "<site URL>/#organization"}) instead of rebuilding the organisation.
 */
class SchemaBuilder
{
    public const TYPES = [
        'Organization', 'LocalBusiness', 'ProfessionalService', 'HomeAndConstructionBusiness', 'Store',
        'Restaurant', 'AutomotiveBusiness', 'HealthAndBeautyBusiness', 'FinancialService', 'LegalService',
    ];

    /**
     * @return string a script tag, or '' when no name is saved (an unnamed organisation is useless)
     */
    public static function script(array $config, string $fallbackUrl): string
    {
        $name = \trim((string) $config['name']);
        $noName = $name === '';
        if ($noName) return '';

        $baseUrl = \rtrim(\trim((string) $config['url']) ?: $fallbackUrl, '/');

        $schema = [
            '@context' => 'https://schema.org',
            '@graph' => [
                self::organisation(config: $config, name: $name, baseUrl: $baseUrl),
                self::website(name: $name, baseUrl: $baseUrl),
            ],
        ];

        // HEX_TAG keeps a value containing "</script>" from ending the tag early
        $json = \json_encode($schema, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_HEX_TAG | \JSON_HEX_AMP);

        return '<script type="application/ld+json">' . $json . '</script>';
    }

    private static function organisation(array $config, string $name, string $baseUrl): array
    {
        $type = \in_array($config['type'], self::TYPES, true) ? $config['type'] : 'LocalBusiness';
        $isLocal = $type !== 'Organization';

        $node = ['@type' => $type, '@id' => $baseUrl . '/#organization', 'name' => $name, 'url' => $baseUrl . '/'];

        $optional = [
            'legalName' => \trim((string) $config['legalName']),
            'description' => \trim((string) $config['description']),
            'email' => \trim((string) $config['email']),
            'telephone' => \trim((string) $config['phone']),
        ];
        $logo = \trim((string) $config['logo']);
        if ($logo !== '') $optional['logo'] = self::absolute(url: $logo, baseUrl: $baseUrl);
        if ($isLocal) $optional['priceRange'] = \trim((string) $config['priceRange']);

        foreach ($optional as $key => $value) {
            if ($value !== '') $node[$key] = $value;
        }

        $address = self::address($config['address'] ?? []);
        if ($address) $node['address'] = $address;

        $contactPoint = self::contactPoint(config: $config);
        if ($contactPoint) $node['contactPoint'] = $contactPoint;

        $sameAs = \array_values(\array_filter(\array_map('trim', (array) $config['sameAs'])));
        if ($sameAs) $node['sameAs'] = $sameAs;

        if (!$isLocal) return $node;

        $geo = self::geo($config['geo'] ?? []);
        if ($geo) $node['geo'] = $geo;

        $hours = self::hours($config['hours'] ?? []);
        if ($hours) $node['openingHoursSpecification'] = $hours;

        return $node;
    }

    private static function website(string $name, string $baseUrl): array
    {
        return [
            '@type' => 'WebSite',
            '@id' => $baseUrl . '/#website',
            'url' => $baseUrl . '/',
            'name' => $name,
            'publisher' => ['@id' => $baseUrl . '/#organization'],
        ];
    }

    private static function address(array $address): ?array
    {
        $map = [
            'streetAddress' => 'street', 'addressLocality' => 'locality', 'addressRegion' => 'region',
            'postalCode' => 'postcode', 'addressCountry' => 'country',
        ];

        $node = ['@type' => 'PostalAddress'];
        foreach ($map as $property => $key) {
            $value = \trim((string) ($address[$key] ?? ''));
            if ($value !== '') $node[$property] = $value;
        }

        $onlyCountry = \array_keys($node) === ['@type', 'addressCountry'];
        $hasDetail = \count($node) > 1 && !$onlyCountry;
        return $hasDetail ? $node : null;
    }

    private static function contactPoint(array $config): ?array
    {
        $phone = \trim((string) $config['phone']);
        $email = \trim((string) $config['email']);
        $hasContact = $phone !== '' || $email !== '';
        if (!$hasContact) return null;

        $node = ['@type' => 'ContactPoint', 'contactType' => 'customer service'];
        if ($phone !== '') $node['telephone'] = $phone;
        if ($email !== '') $node['email'] = $email;

        return $node;
    }

    private static function geo(array $geo): ?array
    {
        $latitude = \trim((string) ($geo['latitude'] ?? ''));
        $longitude = \trim((string) ($geo['longitude'] ?? ''));
        $bothNumeric = \is_numeric($latitude) && \is_numeric($longitude);
        if (!$bothNumeric) return null;

        return ['@type' => 'GeoCoordinates', 'latitude' => (float) $latitude, 'longitude' => (float) $longitude];
    }

    private static function hours(array $hours): array
    {
        $specs = [];
        foreach (SchemaConfig::DAYS as $day) {
            $row = $hours[$day] ?? null;
            $isOpen = \is_array($row) && !($row['closed'] ?? true);
            if (!$isOpen) continue;

            $specs[] = [
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => $day,
                'opens' => (string) $row['opens'],
                'closes' => (string) $row['closes'],
            ];
        }

        return $specs;
    }

    private static function absolute(string $url, string $baseUrl): string
    {
        $isRelative = \str_starts_with($url, '/');
        return $isRelative ? $baseUrl . $url : $url;
    }
}
