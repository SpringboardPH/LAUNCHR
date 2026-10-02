<?php

namespace Tests\Unit\Bir;

use App\Models\SystemSettings;
use App\Services\BIR\BirSettingsService;
use App\Services\BIR\Schemas\Form1601CSchema;
use App\Services\BIR\Schemas\Form2316Schema;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BirSettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_1601c_reads_every_company_detail_that_is_set(): void
    {
        $this->setCompany();

        $this->assertSame([
            'atc_code' => 'WW010',
            'company_tin' => '123-456-789-000',
            'rdo_code' => '043',
            'company_name' => 'Springboard PH Inc.',
            'company_address' => '12 Ayala Ave, Makati City',
            'company_zip' => '1226',
            'company_contact_number' => '0288881234',
            'agent_category' => 'private',
            'company_email' => 'hr@springboardph.com',
        ], BirSettingsService::values('1601-C'));
    }

    public function test_2316_reads_the_same_settings_under_its_own_keys(): void
    {
        $this->setCompany();

        $this->assertSame([
            'present_employer_tin' => '123-456-789-000',
            'present_employer_name' => 'Springboard PH Inc.',
            'present_employer_address' => '12 Ayala Ave, Makati City',
            'present_employer_zip' => '1226',
            'employer_type' => 'main',
        ], BirSettingsService::values('2316'));
    }

    public function test_seed_placeholders_and_blanks_are_left_out(): void
    {
        $this->seed(SystemSettingsSeeder::class);

        // Only the fixed ATC and the real defaults survive; the rest stay pending for the user.
        $this->assertSame(['atc_code' => 'WW010', 'agent_category' => 'private'], BirSettingsService::values('1601-C'));
        $this->assertSame(['employer_type' => 'main'], BirSettingsService::values('2316'));
    }

    public function test_an_all_zero_tin_is_a_placeholder_however_it_is_written(): void
    {
        $this->setting('company_tin', '000000000');

        $this->assertArrayNotHasKey('company_tin', BirSettingsService::values('1601-C'));
    }

    public function test_a_value_outside_the_fields_options_is_left_out(): void
    {
        $this->setting('agent_category', 'Private Corp.');
        $this->setting('employer_type', 'secondary');

        $this->assertArrayNotHasKey('agent_category', BirSettingsService::values('1601-C'));
        $this->assertSame('secondary', BirSettingsService::values('2316')['employer_type']);
    }

    public function test_only_settings_sourced_schema_keys_come_back(): void
    {
        $this->setCompany();

        foreach ([Form1601CSchema::class, Form2316Schema::class] as $schema) {
            $settingsKeys = collect($schema::fields())->where('source', 'settings')->pluck('key')->all();
            $this->assertEqualsCanonicalizing($settingsKeys, array_keys(BirSettingsService::values($schema::FORM_TYPE)));
        }
    }

    public function test_an_unknown_form_type_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BirSettingsService::values('1700');
    }

    private function setCompany(): void
    {
        foreach ([
            'atc_code' => 'WW010',
            'company_tin' => '123456789',
            'rdo_code' => '043',
            'company_name' => 'Springboard PH Inc.',
            'company_address' => '12 Ayala Ave, Makati City',
            'company_zip' => '1226',
            'company_contact_number' => '0288881234',
            'agent_category' => 'private',
            'company_email' => 'hr@springboardph.com',
            'employer_type' => 'main',
        ] as $key => $value) {
            $this->setting($key, $value);
        }
    }

    private function setting(string $key, string $value): void
    {
        SystemSettings::updateOrCreate(['key' => $key], ['value' => $value, 'type' => 'string', 'description' => 'test']);
    }
}
