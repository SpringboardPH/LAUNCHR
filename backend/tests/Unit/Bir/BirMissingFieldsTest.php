<?php

namespace Tests\Unit\Bir;

use App\Services\BIR\BirMissingFields;
use App\Services\BIR\Schemas\Form1601CSchema;
use App\Services\BIR\Schemas\Form2316Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a draft still needs from a person. These run on plain arrays shaped like
 * a real draft (every schema key, contract §3 entries), against the real
 * schemas, with no database — the detector doesn't need one.
 *
 * The case that proves the feature: a mid-year hire's 2316 asks about a previous
 * employer, a full-year employee's 2316 never does, and a mid-year hire who had
 * no previous employer is never asked for one's TIN.
 */
class BirMissingFieldsTest extends TestCase
{
    private const PREVIOUS_EMPLOYER_KEYS = [
        'has_previous_employer',
        'previous_employer_tin',
        'previous_employer_name',
        'taxable_income_previous_employer',
        'taxes_withheld_previous',
    ];

    // ── 2316: the previous-employer case ───────────────────────────────────

    public function test_a_full_year_2316_asks_only_the_unconditionally_required_user_fields(): void
    {
        $keys = $this->missingKeys2316([], ['hired_in_year' => false]);

        // 36 user fields start pending; 5 of them are required for everyone.
        // tax_year and is_mwe are on this list only because nothing fills them yet:
        // the draft's period already holds the year, and the aggregation already
        // decided MWE status from settings. When those are filled upstream this
        // list should shrink to three — update the test then, don't skip it.
        $this->assertSame(
            ['tax_year', 'employee_rdo_code', 'employee_registered_address', 'employee_birthdate', 'is_mwe'],
            $keys,
        );
        foreach (self::PREVIOUS_EMPLOYER_KEYS as $key) {
            $this->assertNotContains($key, $keys);
        }
    }

    public function test_a_mid_year_hire_is_asked_whether_there_was_a_previous_employer_before_any_figures(): void
    {
        $keys = $this->missingKeys2316([], ['hired_in_year' => true]);

        $this->assertSame(
            ['tax_year', 'period_from', 'employee_rdo_code', 'employee_registered_address',
                'employee_birthdate', 'is_mwe', 'has_previous_employer'],
            $keys,
        );
    }

    public function test_a_yes_brings_in_the_previous_employers_tin_name_income_and_tax_withheld_in_form_order(): void
    {
        $keys = $this->missingKeys2316(['has_previous_employer' => true], ['hired_in_year' => true]);

        $this->assertSame(
            ['previous_employer_tin', 'previous_employer_name', 'taxable_income_previous_employer',
                'taxes_withheld_previous'],
            array_values(array_intersect($keys, self::PREVIOUS_EMPLOYER_KEYS)),
        );
        $this->assertNotContains('previous_employer_address', $keys, 'Item 18 is optional even with a previous employer.');
    }

    public function test_previous_employer_tax_withheld_is_asked_even_when_their_taxable_income_is_zero(): void
    {
        $keys = $this->missingKeys2316(
            ['has_previous_employer' => true, 'taxable_income_previous_employer' => '0.00'], ['hired_in_year' => true]);

        $this->assertContains('taxes_withheld_previous', $keys);
    }

    public function test_a_no_means_no_previous_employer_questions_at_all(): void
    {
        $keys = $this->missingKeys2316(['has_previous_employer' => false], ['hired_in_year' => true]);

        foreach (self::PREVIOUS_EMPLOYER_KEYS as $key) {
            $this->assertNotContains($key, $keys);
        }
    }

    public static function gateAnswers(): array
    {
        return [
            'bool true' => [true, true],
            'string true' => ['true', true],
            'string 1' => ['1', true],
            'yes' => ['yes', true],
            'bool false' => [false, false],
            'string false' => ['false', false],
            'string 0' => ['0', false],
            'no' => ['no', false],
        ];
    }

    #[DataProvider('gateAnswers')]
    public function test_a_yes_or_no_is_read_the_same_whatever_form_it_arrives_in(mixed $answer, bool $isYes): void
    {
        $keys = $this->missingKeys2316(['has_previous_employer' => $answer], ['hired_in_year' => true]);

        $this->assertSame($isYes, in_array('previous_employer_tin', $keys, true));
        $this->assertNotContains('has_previous_employer', $keys);
    }

    public function test_an_unreadable_yes_or_no_is_asked_again_rather_than_taken_as_no(): void
    {
        $keys = $this->missingKeys2316(['has_previous_employer' => 'maybe'], ['hired_in_year' => true]);

        $this->assertContains('has_previous_employer', $keys);
        $this->assertNotContains('previous_employer_tin', $keys);
    }

    // ── Other conditions ─────────────────────────────────────────────────

    public function test_an_mwe_is_asked_for_the_minimum_wage_rates(): void
    {
        $mwe = $this->missingKeys2316(['is_mwe' => true], ['hired_in_year' => false]);
        $notMwe = $this->missingKeys2316(['is_mwe' => false], ['hired_in_year' => false]);

        $this->assertContains('mwe_daily_rate', $mwe);
        $this->assertContains('mwe_monthly_rate', $mwe);
        $this->assertNotContains('mwe_daily_rate', $notMwe);
    }

    public function test_substituted_filing_asks_for_the_id_then_its_details_but_never_the_signatures(): void
    {
        $context = ['hired_in_year' => false];

        $before = $this->missingKeys2316(['is_substituted_filing' => true], $context);
        $after = $this->missingKeys2316(['is_substituted_filing' => true, 'employee_ctc_or_id' => 'CTC 12345'], $context);

        $this->assertContains('employee_ctc_or_id', $before);
        $this->assertNotContains('ctc_place_of_issue', $before);
        $this->assertContains('ctc_place_of_issue', $after);
        $this->assertContains('ctc_date_issued', $after);
        $this->assertNotContains('substituted_employer_signature', $after, 'Signed by hand after printing.');
        $this->assertNotContains('substituted_employee_signature', $after);
    }

    public function test_optional_1601c_fields_left_blank_are_not_missing(): void
    {
        $keys = $this->keys(BirMissingFields::detect(
            Form1601CSchema::fields(),
            $this->draftFields(Form1601CSchema::fields(), [], ['company_email', 'company_contact_number']),
        ));

        $this->assertSame(['return_period', 'is_amended', 'sheets_attached'], $keys);
    }

    public function test_1601c_tax_relief_details_follow_the_answer(): void
    {
        $schema = Form1601CSchema::fields();

        $yes = BirMissingFields::detect($schema, $this->draftFields($schema, ['has_tax_relief' => true]));
        $no = BirMissingFields::detect($schema, $this->draftFields($schema, ['has_tax_relief' => false]));

        $details = array_values(array_filter($yes, fn ($m) => $m['key'] === 'tax_relief_details'));
        $this->assertCount(1, $details);
        $this->assertSame(BirMissingFields::REASON_CONDITION, $details[0]['reason']);
        $this->assertSame(['field' => 'has_tax_relief', 'equals' => true], $details[0]['required_when']);
        $this->assertNotContains('tax_relief_details', $this->keys($no));
    }

    public function test_an_amended_1601c_asks_for_the_tax_previously_remitted(): void
    {
        $schema = Form1601CSchema::fields();

        $this->assertContains('previously_remitted_tax',
            $this->keys(BirMissingFields::detect($schema, $this->draftFields($schema, ['is_amended' => true]))));
        $this->assertNotContains('previously_remitted_tax',
            $this->keys(BirMissingFields::detect($schema, $this->draftFields($schema, ['is_amended' => false]))));
    }

    // ── What counts as filled ─────────────────────────────────────────────

    public function test_a_required_field_the_system_could_not_fill_is_a_record_gap(): void
    {
        $schema = Form2316Schema::fields();
        $missing = BirMissingFields::detect(
            $schema,
            $this->draftFields($schema, [], ['employee_tin', 'employee_contact_number']),
            ['hired_in_year' => false],
        );

        $tin = array_values(array_filter($missing, fn ($m) => $m['key'] === 'employee_tin'));
        $this->assertCount(1, $tin);
        $this->assertSame(BirMissingFields::REASON_RECORD_GAP, $tin[0]['reason']);
        $this->assertNotContains('employee_contact_number', $this->keys($missing), 'Item 8 is optional.');
    }

    public function test_a_field_absent_from_the_draft_counts_as_pending(): void
    {
        // Fixture drafts carry only a handful of keys.
        $keys = $this->keys(BirMissingFields::detect(Form1601CSchema::fields(), []));

        $this->assertContains('return_period', $keys);
        $this->assertContains('total_compensation', $keys);
    }

    public function test_an_entry_claiming_an_origin_but_holding_no_value_is_treated_as_missing(): void
    {
        $schema = Form1601CSchema::fields();
        $fields = $this->draftFields($schema);
        $fields['total_taxes_withheld']['value'] = null; // breaks contract invariant 1

        $this->assertContains('total_taxes_withheld', $this->keys(BirMissingFields::detect($schema, $fields)));
    }

    // ── The schemas themselves ────────────────────────────────────────────

    public function test_the_shipped_schemas_have_no_broken_conditions(): void
    {
        $this->assertSame([], BirMissingFields::schemaProblems(Form1601CSchema::fields()));
        $this->assertSame([], BirMissingFields::schemaProblems(Form2316Schema::fields()));
    }

    public function test_a_condition_pointing_at_a_field_that_does_not_exist_fails_loudly(): void
    {
        $schema = [[
            'key' => 'details', 'item' => '1', 'label' => 'Details', 'type' => 'text',
            'source' => 'user', 'required' => false,
            'required_when' => ['field' => 'has_detials', 'equals' => true], // typo on purpose
        ]];

        $this->assertNotSame([], BirMissingFields::schemaProblems($schema));
        $this->expectException(InvalidArgumentException::class);
        BirMissingFields::detect($schema, []);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * A draft's fields object as BirFormController::buildFields() makes it: every
     * schema key, payroll and settings fields filled, everything else pending —
     * then $answers applied as if the user had supplied them.
     *
     * @param  array<int, array<string, mixed>>  $schema
     * @param  array<string, mixed>  $answers
     * @param  array<int, string>  $leaveBlank  payroll/settings keys the system had nothing for
     * @return array<string, array<string, mixed>>
     */
    private function draftFields(array $schema, array $answers = [], array $leaveBlank = []): array
    {
        $fields = [];
        foreach ($schema as $field) {
            $systemFilled = in_array($field['source'], ['payroll', 'settings'], true)
                && !in_array($field['key'], $leaveBlank, true);

            // Any readable value will do; booleans need a real bool or they read as unanswered.
            $value = $field['type'] === 'boolean' ? true : 'x';

            $fields[$field['key']] = $this->entry($systemFilled ? $value : null, $systemFilled ? $field['source'] : 'pending');
        }
        foreach ($answers as $key => $value) {
            $fields[$key] = $this->entry($value, 'user');
        }

        return $fields;
    }

    private function entry(mixed $value, string $origin): array
    {
        return [
            'value' => $value, 'origin' => $origin, 'edited' => false,
            'system_value' => null, 'edited_by' => null, 'edited_at' => null,
        ];
    }

    /** @return array<int, string> */
    private function missingKeys2316(array $answers, array $context): array
    {
        $schema = Form2316Schema::fields();

        return $this->keys(BirMissingFields::detect($schema, $this->draftFields($schema, $answers), $context));
    }

    /** @return array<int, string> */
    private function keys(array $missing): array
    {
        return array_column($missing, 'key');
    }
}
