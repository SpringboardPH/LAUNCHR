<?php

namespace Tests\Unit\Bir;

use App\Services\BIR\BirAggregationService;
use App\Services\BIR\Schemas\FieldFormat;
use App\Services\BIR\Schemas\Form1601CSchema;
use App\Services\BIR\Schemas\Form2316Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BirFieldFormatTest extends TestCase
{
    public static function schemas(): array
    {
        return ['1601-C' => [Form1601CSchema::fields()], '2316' => [Form2316Schema::fields()]];
    }

    #[DataProvider('schemas')]
    public function test_every_format_on_a_schema_is_a_known_one_on_a_text_field(array $fields): void
    {
        foreach (array_filter($fields, fn (array $f) => isset($f['format'])) as $field) {
            $this->assertArrayHasKey($field['format'], FieldFormat::FORMATS, $field['key']);
            $this->assertContains($field['type'], ['string', 'text'], $field['key']);
        }
    }

    public function test_the_fields_with_a_format(): void
    {
        $formats = fn (array $fields) => array_column(array_filter($fields, fn (array $f) => isset($f['format'])), 'format', 'key');

        $this->assertSame([
            'company_tin' => 'tin', 'rdo_code' => 'rdo', 'company_address' => 'address', 'company_zip' => 'zip',
            'company_contact_number' => 'phone', 'company_email' => 'email',
        ], $formats(Form1601CSchema::fields()));
        $this->assertSame([
            'period_from' => 'mmdd', 'period_to' => 'mmdd', 'employee_tin' => 'tin', 'employee_rdo_code' => 'rdo',
            'employee_registered_address' => 'address', 'employee_registered_zip' => 'zip',
            'employee_home_address' => 'address', 'employee_home_zip' => 'zip', 'employee_contact_number' => 'phone',
            'present_employer_tin' => 'tin', 'present_employer_address' => 'address', 'present_employer_zip' => 'zip',
            'previous_employer_tin' => 'tin', 'previous_employer_address' => 'address', 'previous_employer_zip' => 'zip',
        ], $formats(Form2316Schema::fields()));
    }

    public static function examples(): array
    {
        return [
            'tin, 14 digits' => ['tin', '123-456-789-00000', true],
            'tin, 3-digit branch' => ['tin', '123-456-789-000', false],
            'tin without dashes' => ['tin', '123456789000', false],
            'tin with no branch' => ['tin', '123-456-789', false],
            'rdo, 3 digits' => ['rdo', '043', true],
            'rdo, 2 digits and a letter' => ['rdo', '21A', true],
            'rdo, 2 digits' => ['rdo', '43', false],
            'rdo, lowercase letter' => ['rdo', '21a', false],
            'zip' => ['zip', '1004', true],
            'zip, 5 digits' => ['zip', '10040', false],
            'mobile' => ['phone', '09171234567', true],
            'mobile with +63' => ['phone', '+63 917 123 4567', true],
            'landline' => ['phone', '(02) 8123-4567', true],
            'phone, letters' => ['phone', 'call me', false],
            'phone, too short' => ['phone', '12345', false],
            'email' => ['email', 'payroll@company.com', true],
            'email, no domain' => ['email', 'payroll@', false],
            'mmdd' => ['mmdd', '07/01', true],
            'mmdd, leap day' => ['mmdd', '02/29', true],
            'mmdd, no such day' => ['mmdd', '02/30', false],
            'mmdd, month 13' => ['mmdd', '13/01', false],
            'mmdd, no slash' => ['mmdd', '0701', false],
            'address' => ['address', '12 Mabini St., Brgy. San Roque, Quezon City', true],
            'address, no house number' => ['address', 'Sitio Malinis, Brgy. Lawa, Calamba City', true],
            'address, more parts and an ñ' => ['address', 'Unit 5B, 123 Ayala Ave., Brgy. San Lorenzo, Parañaque City', true],
            'address, words only' => ['address', 'blah blah blah', false],
            'address, no commas' => ['address', '12 Mabini St. Brgy. San Roque Quezon City', false],
            'address, two parts' => ['address', 'Mabini St., Quezon City', false],
            'address, a part with no letters' => ['address', '12, Brgy. San Roque, Quezon City', false],
            'address, an empty part' => ['address', 'Mabini St., , Quezon City', false],
        ];
    }

    #[DataProvider('examples')]
    public function test_values_are_read_as_stored(string $format, string $value, bool $ok): void
    {
        $this->assertSame($ok, FieldFormat::matches($format, $value));
    }

    public function test_figures_the_system_fills_in_already_follow_the_format(): void
    {
        $this->assertTrue(FieldFormat::matches('tin', BirAggregationService::formatTin('123456789')));
        $this->assertTrue(FieldFormat::matches('tin', BirAggregationService::formatTin('123-456-789-000')));
        $this->assertTrue(FieldFormat::matches('tin', BirAggregationService::formatTin('123-456-789-00000')));
        $this->assertTrue(FieldFormat::matches('mmdd', '01/01'));
        $this->assertTrue(FieldFormat::matches('mmdd', '12/31'));
    }

    /** @return array<string, array{array<string, mixed>, mixed, mixed}> */
    public static function normalized(): array
    {
        $text = ['type' => 'string'];

        return [
            'name in capitals' => [$text, 'Juan dela Cruz', 'JUAN DELA CRUZ'],
            'address in capitals, ñ too' => [['type' => 'text', 'format' => 'address'], '12 Mabini St., Brgy. Sto. Niño, Parañaque City', '12 MABINI ST., BRGY. STO. NIÑO, PARAÑAQUE CITY'],
            'rdo letter' => [$text + ['format' => 'rdo'], '21a', '21A'],
            'spaces trimmed' => [$text, '  Acme Corp ', 'ACME CORP'],
            'tin, 3-digit branch' => [$text + ['format' => 'tin'], '123-456-789-000', '123-456-789-00000'],
            'tin, branch 1' => [$text + ['format' => 'tin'], '123-456-789-001', '123-456-789-00001'],
            'tin, no dashes' => [$text + ['format' => 'tin'], '123456789000', '123-456-789-00000'],
            'tin, no branch' => [$text + ['format' => 'tin'], '123 456 789', '123-456-789-00000'],
            'tin, too short is left for the check' => [$text + ['format' => 'tin'], '1234', '1234'],
            'tin, too long is left for the check' => [$text + ['format' => 'tin'], '123-456-789-000000', '123-456-789-000000'],
            'email keeps its case' => [$text + ['format' => 'email'], 'Payroll@Company.com', 'Payroll@Company.com'],
            'a choice is not text' => [['type' => 'enum', 'options' => ['main', 'secondary']], 'main', 'main'],
            'a date is not text' => [['type' => 'date'], '1990-05-31', '1990-05-31'],
            'an amount is not text' => [['type' => 'decimal'], '100.00', '100.00'],
            'blank stays blank' => [$text, null, null],
        ];
    }

    /** @param  array<string, mixed>  $field */
    #[DataProvider('normalized')]
    public function test_text_is_stored_in_capitals_and_a_tin_as_14_digits(array $field, mixed $value, mixed $stored): void
    {
        $this->assertSame($stored, FieldFormat::normalize($field, $value));
    }

    public function test_a_value_that_is_not_text_does_not_match(): void
    {
        $this->assertFalse(FieldFormat::matches('zip', 1004));
        $this->assertFalse(FieldFormat::matches('tin', null));
    }

    public function test_an_unknown_format_fails_loudly(): void
    {
        $this->assertSame('a 4-digit ZIP code like 1004', FieldFormat::describe('zip'));
        $this->expectException(InvalidArgumentException::class);
        FieldFormat::matches('postcode', '1004');
    }
}
