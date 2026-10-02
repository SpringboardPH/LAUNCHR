<?php

namespace App\Services\BIR;

use App\Models\SystemSettings;
use App\Services\BIR\Schemas\Form1601CSchema;
use App\Services\BIR\Schemas\Form2316Schema;

/**
 * The company's own registration details, keyed by the schema's 'settings'-sourced
 * field keys — the settings counterpart of BirAggregationService. Values come back
 * as plain strings. A setting that is blank, still a seed placeholder, or not one
 * of the field's options is left out, so the draft marks it pending and the user is
 * asked rather than shown a made-up company.
 */
class BirSettingsService
{
    /** 2316 field keys that read a setting under a different name; every other key reads its own name. */
    private const SETTING_FOR_KEY = [
        'present_employer_tin' => 'company_tin',
        'present_employer_name' => 'company_name',
        'present_employer_address' => 'company_address',
        'present_employer_zip' => 'company_zip',
    ];

    /** The values SystemSettingsSeeder ships with — a setting still equal to one of these hasn't been set. */
    private const PLACEHOLDERS = [
        'company_tin' => '000-000-000-000',
        'rdo_code' => '000',
        'company_name' => 'Company Name Inc.',
        'company_address' => 'Unit 000, Sample Building, Sample City',
        'company_zip' => '0000',
    ];

    /** @return array<string, string> field key => value, only for settings that are really set */
    public static function values(string $formType): array
    {
        $fields = collect(match ($formType) {
            Form1601CSchema::FORM_TYPE => Form1601CSchema::fields(),
            Form2316Schema::FORM_TYPE => Form2316Schema::fields(),
            default => throw new \InvalidArgumentException("Unknown BIR form type: {$formType}"),
        })->where('source', 'settings');

        $settings = SystemSettings::query()
            ->whereIn('key', $fields->map(fn (array $f) => self::SETTING_FOR_KEY[$f['key']] ?? $f['key']))
            ->get()
            ->mapWithKeys(fn (SystemSettings $s) => [$s->key => trim((string) $s->value)]);

        $values = [];
        foreach ($fields as $field) {
            $setting = self::SETTING_FOR_KEY[$field['key']] ?? $field['key'];
            $value = $settings[$setting] ?? '';

            if ($value === '' || $value === (self::PLACEHOLDERS[$setting] ?? null)) {
                continue;
            }
            if ($setting === 'company_tin') {
                if (trim(preg_replace('/\D/', '', $value), '0') === '') {
                    continue; // all zeros, however it's punctuated
                }
                $value = BirAggregationService::formatTin($value);
            }
            if (isset($field['options']) && !in_array($value, $field['options'], true)) {
                continue;
            }

            $values[$field['key']] = $value;
        }

        return $values;
    }
}
