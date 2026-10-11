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
            'company_tin' => 'tin', 'rdo_code' => 'rdo', 'company_zip' => 'zip',
            'company_contact_number' => 'phone', 'company_email' => 'email',
        ], $formats(Form1601CSchema::fields()));
        $this->assertSame([
            'period_from' => 'mmdd', 'period_to' => 'mmdd', 'employee_tin' => 'tin', 'employee_rdo_code' => 'rdo',
            'employee_registered_zip' => 'zip', 'employee_home_zip' => 'zip', 'employee_contact_number' => 'phone',
            'present_employer_tin' => 'tin', 'present_employer_zip' => 'zip',
            'previous_employer_tin' => 'tin', 'previous_employer_zip' => 'zip',
        ], $formats(Form2316Schema::fields()));
    }

    public static function examples(): array
    {
        return [
            'tin, 3-digit branch' => ['tin', '123-456-789-000', true],
            'tin, 5-digit branch' => ['tin', '123-456-789-00000', true],
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
        $this->assertTrue(FieldFormat::matches('tin', BirAggregationService::formatTin('123-456-789-00000')));
        $this->assertTrue(FieldFormat::matches('mmdd', '01/01'));
        $this->assertTrue(FieldFormat::matches('mmdd', '12/31'));
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
