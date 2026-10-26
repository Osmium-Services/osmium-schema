<?php

declare(strict_types=1);

namespace Osmium\Services\Schema\Controllers;

use Osmium\Core\Library\ServiceLoader;
use Osmium\Core\Models\ServiceModel;
use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Services\Geocoder\Models\PostcodeGeocoder;
use Osmium\Services\Schema\Models\SchemaBuilder;
use Osmium\Services\Schema\Models\SchemaConfig;

/**
 * Schema settings: a full-page form POST/redirect flow, like Cookie Consent.
 *
 * Routes:
 *   - index() → /admin/settings/schema/  (GET shows the form, POST saves it)
 */
class SchemaController extends AdminController
{
    private const CONFIG_FILE_PATH = 'app/config/services/schema.json.php';

    public function index(): void
    {
        $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
        if ($isPost) $this->handleSubmit();

        $this->refreshLookup();
        $this->data['admin']['config']['schema'] = SchemaConfig::get();
        $this->data['admin']['inherited'] = $this->inheritedValues();
        $this->data['admin']['types'] = SchemaBuilder::TYPES;
        $this->data['admin']['settingsSaved'] = $_SESSION['schema_settings_saved'] ?? false;
        $this->data['admin']['settingsError'] = $_SESSION['schema_settings_error'] ?? false;
        unset($_SESSION['schema_settings_saved'], $_SESSION['schema_settings_error']);

        $this->setView('schema/index.phtml');
    }

    /**
     * What an empty field will fall back to on the site, shown as placeholder text.
     */
    private function inheritedValues(): array
    {
        $logos = $this->admin->branding->currentLogos();
        $logoUrl = \Osmium\Modules\Admin\Services\AdminBranding::LOGO_URL;
        $logoFile = $logos['dark'] ?? $logos['light'] ?? null;

        $site = ['name' => $this->osmium->config->site->name, 'logoDark' => $logoFile ? $logoUrl . $logoFile : ''];
        $company = (array) ($this->osmium->config->company ?? []);

        $context = (new ServiceLoader(new ServiceModel($this->osmium->dataSource)))->serviceConfigContext();

        $inherited = SchemaConfig::inherited(site: $site, company: $company, socialLinks: $context['osmiumSocialLinks'] ?? []);
        $inherited['url'] = (string) $this->osmium->config->site->FQDN;

        return $inherited;
    }

    private function handleSubmit(): void
    {
        $csrfValid = $this->admin->auth->validateCsrf();
        if (!$csrfValid) {
            $_SESSION['schema_settings_error'] = 'Invalid form submission. Please try again.';
            $this->redirect('settings/schema/');
        }

        $current = SchemaConfig::get();
        $errors = [];

        $type = \trim($_POST['type'] ?? '');
        $typeValid = \in_array($type, SchemaBuilder::TYPES, true);
        if (!$typeValid) $errors[] = 'Unknown type - kept the previous one.';

        $config = [
            'type' => $typeValid ? $type : $current['type'],
            'name' => $this->text('name'),
            'legalName' => $this->text('legal_name'),
            'url' => $this->readUrl(field: 'url', label: 'Site URL', errors: $errors, current: $current['url']),
            'logo' => $this->text('logo'),
            'description' => $this->text('description'),
            'email' => $this->text('email'),
            'phone' => $this->text('phone'),
            'priceRange' => $this->text('price_range'),
            'geo' => $this->readGeo(errors: $errors, current: $current['geo']),
            'geoLookup' => $current['geoLookup'],
            'sameAs' => $this->readSameAs(errors: $errors),
        ];
        $config['geoLookup'] = $this->lookup(config: $config, errors: $errors);

        $this->saveConfig($config);

        $this->admin->model->changelog->log(description: 'Updated schema settings', recordType: 'settings');

        SchemaConfig::clearCache();

        $_SESSION['schema_settings_saved'] = true;
        if ($errors) $_SESSION['schema_settings_error'] = \implode(' ', $errors);
        $this->redirect('settings/schema/');
    }

    /**
     * Typed coordinates (both or neither) override the lookup.
     */
    private function readGeo(array &$errors, array $current): array
    {
        $latitude = $this->text('latitude');
        $longitude = $this->text('longitude');

        $bothEmpty = $latitude === '' && $longitude === '';
        if ($bothEmpty) return [];

        $inRange = \is_numeric($latitude) && \is_numeric($longitude)
            && \abs((float) $latitude) <= 90 && \abs((float) $longitude) <= 180;
        if ($inRange) return ['latitude' => $latitude, 'longitude' => $longitude];

        $errors[] = 'Latitude and longitude must both be numbers in range - kept the previous values.';
        return $current;
    }

    /**
     * Looks the company postcode up (Postcode Geocoder service) when no coordinates are typed and the answer kept
     * for it is missing or for a different postcode. Returns the lookup to keep.
     */
    private function lookup(array $config, array &$errors): array
    {
        $kept = (array) ($config['geoLookup'] ?? []);
        $typed = SchemaConfig::numericPair((array) $config['geo']);
        if ($typed) return $kept;

        $company = (array) ($this->osmium->config->company ?? []);
        $postcode = SchemaConfig::companyPostcode($company['location'] ?? null, (string) ($company['address'] ?? ''));
        $noPostcode = $postcode === '';
        if ($noPostcode) return [];

        $upToDate = SchemaConfig::samePostcode($postcode, (string) ($kept['postcode'] ?? '')) && SchemaConfig::numericPair($kept);
        if ($upToDate) return $kept;

        $found = (new PostcodeGeocoder())->geocode($postcode);
        if ($found) return ['postcode' => $postcode, 'latitude' => (string) $found['latitude'], 'longitude' => (string) $found['longitude']];

        $errors[] = "Could not look up coordinates for \"{$postcode}\" - type latitude and longitude by hand.";
        return [];
    }

    /** Keeps the lookup in step with the company postcode whenever the page is opened (Location may have changed). */
    private function refreshLookup(): void
    {
        $config = SchemaConfig::get();
        $errors = [];
        $lookup = $this->lookup(config: $config, errors: $errors);
        if ($errors) $_SESSION['schema_settings_error'] = \implode(' ', $errors);

        $changed = $lookup !== (array) $config['geoLookup'];
        if (!$changed) return;

        $config['geoLookup'] = $lookup;
        $this->saveConfig($config);
        SchemaConfig::clearCache();
    }

    private function text(string $field): string
    {
        return \trim((string) ($_POST[$field] ?? ''));
    }

    private function readUrl(string $field, string $label, array &$errors, string $current): string
    {
        $value = $this->text($field);
        $isEmpty = $value === '';
        if ($isEmpty) return '';

        $isHttp = (bool) \preg_match('#^https?://#i', $value);
        if ($isHttp) return $value;

        $errors[] = "{$label} must start with http:// or https:// - kept the previous value.";
        return $current;
    }

    private function readSameAs(array &$errors): array
    {
        $lines = \preg_split('/\R/', (string) ($_POST['same_as'] ?? '')) ?: [];
        $urls = [];
        foreach ($lines as $line) {
            $line = \trim($line);
            if ($line === '') continue;

            $isHttp = (bool) \preg_match('#^https?://#i', $line);
            if ($isHttp) $urls[] = $line;
            if (!$isHttp) $errors[] = "Dropped \"{$line}\" from social links - it must start with http:// or https://.";
        }

        return $urls;
    }

    private function saveConfig(array $schema): void
    {
        $dir = \dirname(self::CONFIG_FILE_PATH);
        $dirExists = \is_dir($dir);
        if (!$dirExists) \mkdir(directory: $dir, permissions: 0755, recursive: true);

        $json = \json_encode(
            value: ['schema' => $schema],
            flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        \file_put_contents(self::CONFIG_FILE_PATH, "<?php exit(); ?>\n" . $json . "\n");
    }
}
