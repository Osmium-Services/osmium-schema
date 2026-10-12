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

        $this->data['admin']['config']['schema'] = SchemaConfig::get();
        $this->data['admin']['inherited'] = $this->inheritedValues();
        $this->data['admin']['types'] = SchemaBuilder::TYPES;
        $this->data['admin']['days'] = SchemaConfig::DAYS;
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

        return SchemaConfig::inherited(site: $site, company: $company, socialLinks: $context['osmiumSocialLinks'] ?? []);
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

        $geo = $this->readGeo(errors: $errors, current: $current['geo'], postcode: $this->text('postcode') ?: $this->companyPostcode());

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
            'address' => [
                'street' => $this->text('street'),
                'locality' => $this->text('locality'),
                'region' => $this->text('region'),
                'postcode' => $this->text('postcode'),
                'country' => $this->text('country'),
            ],
            'geo' => $geo,
            'hours' => $this->readHours(errors: $errors, current: $current['hours']),
            'sameAs' => $this->readSameAs(errors: $errors),
        ];

        $this->saveConfig($config);

        $this->admin->model->changelog->log(description: 'Updated schema settings', recordType: 'settings');

        SchemaConfig::clearCache();

        $_SESSION['schema_settings_saved'] = true;
        if ($errors) $_SESSION['schema_settings_error'] = \implode(' ', $errors);
        $this->redirect('settings/schema/');
    }

    /**
     * Only used to look up coordinates: Branding's address is one free-text box, so it is not split into fields.
     */
    private function companyPostcode(): string
    {
        $address = (string) ($this->osmium->config->company->address ?? '');
        $found = \preg_match('/\b[A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}\b/i', $address, $match);

        return $found ? $match[0] : '';
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

    private function readGeo(array &$errors, array $current, string $postcode): array
    {
        $latitude = $this->text('latitude');
        $longitude = $this->text('longitude');

        $bothEmpty = $latitude === '' && $longitude === '';
        if ($bothEmpty) return $this->geocodePostcode(postcode: $postcode, errors: $errors);

        $inRange = \is_numeric($latitude) && \is_numeric($longitude)
            && \abs((float) $latitude) <= 90 && \abs((float) $longitude) <= 180;
        if ($inRange) return ['latitude' => $latitude, 'longitude' => $longitude];

        $errors[] = 'Latitude and longitude must both be numbers in range - kept the previous values.';
        return $current;
    }

    /**
     * Typed coordinates always win; this only runs when both boxes are empty.
     */
    private function geocodePostcode(string $postcode, array &$errors): array
    {
        $empty = ['latitude' => '', 'longitude' => ''];
        $noPostcode = $postcode === '';
        if ($noPostcode) return $empty;

        $found = (new PostcodeGeocoder())->geocode($postcode);
        if ($found) return ['latitude' => (string) $found['latitude'], 'longitude' => (string) $found['longitude']];

        $errors[] = "Could not look up coordinates for \"{$postcode}\" - enter latitude and longitude by hand.";
        return $empty;
    }

    private function readHours(array &$errors, array $current): array
    {
        $hours = [];
        $badTime = false;
        foreach (SchemaConfig::DAYS as $day) {
            $key = \strtolower($day);
            $opens = $this->text("hours_{$key}_opens");
            $closes = $this->text("hours_{$key}_closes");
            $timesValid = $this->isTime($opens) && $this->isTime($closes);

            if (!$timesValid) {
                $badTime = true;
                $hours[$day] = $current[$day];
                continue;
            }

            $hours[$day] = ['closed' => !isset($_POST["hours_{$key}_open"]), 'opens' => $opens, 'closes' => $closes];
        }

        if ($badTime) $errors[] = 'Times must look like 09:00 - some days kept their previous hours.';

        return $hours;
    }

    private function isTime(string $value): bool
    {
        return (bool) \preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value);
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
