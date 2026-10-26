<?php

declare(strict_types=1);

namespace Osmium\Services\Schema\Models;

/**
 * Turns the country name typed on Company > Location ("United Kingdom") into the two-letter ISO code schema.org
 * wants ("GB"). A two-letter code typed there is kept as it is; a name not in the list gives '' so no country is output.
 */
class CountryCodes
{
    private const NAMES = [
        'united kingdom' => 'GB', 'uk' => 'GB', 'great britain' => 'GB', 'britain' => 'GB', 'england' => 'GB',
        'scotland' => 'GB', 'wales' => 'GB', 'northern ireland' => 'GB',
        'ireland' => 'IE', 'republic of ireland' => 'IE',
        'united states' => 'US', 'united states of america' => 'US', 'usa' => 'US', 'us' => 'US',
        'canada' => 'CA', 'australia' => 'AU', 'new zealand' => 'NZ', 'south africa' => 'ZA',
        'france' => 'FR', 'germany' => 'DE', 'spain' => 'ES', 'portugal' => 'PT', 'italy' => 'IT',
        'netherlands' => 'NL', 'the netherlands' => 'NL', 'belgium' => 'BE', 'luxembourg' => 'LU',
        'switzerland' => 'CH', 'austria' => 'AT', 'denmark' => 'DK', 'sweden' => 'SE', 'norway' => 'NO',
        'finland' => 'FI', 'iceland' => 'IS', 'poland' => 'PL', 'czech republic' => 'CZ', 'czechia' => 'CZ',
        'greece' => 'GR', 'malta' => 'MT', 'cyprus' => 'CY', 'india' => 'IN', 'china' => 'CN', 'japan' => 'JP',
        'united arab emirates' => 'AE', 'uae' => 'AE',
    ];

    public static function fromName(string $name): string
    {
        $key = \strtolower(\trim($name));
        $isCode = \preg_match('/^[a-z]{2}$/', $key) === 1;
        if ($isCode) return \strtoupper($key);

        return self::NAMES[$key] ?? '';
    }
}
