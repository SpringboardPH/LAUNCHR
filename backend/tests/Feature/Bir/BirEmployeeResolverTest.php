<?php

namespace Tests\Feature\Bir;

use App\Models\Employee;
use App\Services\BIR\BirEmployeeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The resolver must be forgiving about spelling and strict about ambiguity.
 * Every test below is one or the other.
 */
class BirEmployeeResolverTest extends TestCase
{
    use RefreshDatabase;

    private BirEmployeeResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new BirEmployeeResolver;

        $this->employee('EMP003', 'Juan', 'Cruz');
        $this->employee('EMP021', 'Juan', 'Dela Cruz');
        $this->employee('EMP004', 'Ana', 'Garcia');
        $this->employee('EMP099', 'Pedro', 'Santos', 'inactive');
    }

    private function employee(string $id, string $first, string $last, string $status = 'active'): Employee
    {
        return Employee::create([
            'employee_id' => $id,
            'first_name' => $first,
            'last_name' => $last,
            'email' => strtolower($first) . '.' . str_replace(' ', '', strtolower($last)) . '@example.com',
            'position' => 'Staff',
            'department' => 'Finance',
            'hire_date' => '2022-01-01',
            'salary' => 30000,
            'status' => $status,
        ]);
    }

    public function test_an_employee_id_matches_exactly(): void
    {
        $result = $this->resolver->resolve('EMP021');

        $this->assertSame(BirEmployeeResolver::MATCHED, $result['status']);
        $this->assertSame('Dela Cruz', $result['employee']->last_name);
    }

    public function test_an_exact_full_name_matches(): void
    {
        $result = $this->resolver->resolve('Ana Garcia');

        $this->assertSame(BirEmployeeResolver::MATCHED, $result['status']);
        $this->assertSame('EMP004', $result['employee']->employee_id);
    }

    public function test_case_and_punctuation_are_ignored(): void
    {
        $result = $this->resolver->resolve('  ana  GARCIA ');

        $this->assertSame(BirEmployeeResolver::MATCHED, $result['status']);
    }

    public function test_two_people_named_juan_are_not_guessed_between(): void
    {
        $result = $this->resolver->resolve('Juan');

        $this->assertSame(BirEmployeeResolver::AMBIGUOUS, $result['status']);
        $this->assertCount(2, $result['candidates']);
        $this->assertNull($result['employee']);
    }

    public function test_an_exact_name_beats_a_looser_match(): void
    {
        // "Juan Cruz" is exactly EMP003, even though it is also a token
        // subset of "Juan Dela Cruz". The exact tier runs first and wins.
        $result = $this->resolver->resolve('Juan Cruz');

        $this->assertSame(BirEmployeeResolver::MATCHED, $result['status']);
        $this->assertSame('EMP003', $result['employee']->employee_id);
    }

    public function test_a_transposed_typo_still_finds_the_person(): void
    {
        // "Gracia" for "Garcia" is one slip of the fingers but two edits by
        // Levenshtein, which is why the resolver also checks overall similarity.
        $result = $this->resolver->resolve('Ana Gracia');

        $this->assertSame(BirEmployeeResolver::MATCHED, $result['status']);
        $this->assertSame('EMP004', $result['employee']->employee_id);
    }

    public function test_a_single_character_typo_still_finds_the_person(): void
    {
        $result = $this->resolver->resolve('Ana Garcis');

        $this->assertSame(BirEmployeeResolver::MATCHED, $result['status']);
        $this->assertSame('EMP004', $result['employee']->employee_id);
    }

    public function test_similar_but_different_names_do_not_merge(): void
    {
        $this->employee('EMP050', 'Ann', 'Garcia');

        // Both exist now, so an exact query must still land on exactly one.
        $result = $this->resolver->resolve('Ann Garcia');

        $this->assertSame(BirEmployeeResolver::MATCHED, $result['status']);
        $this->assertSame('EMP050', $result['employee']->employee_id);
    }

    public function test_a_reversed_name_is_found(): void
    {
        $result = $this->resolver->resolve('Garcia Ana');

        $this->assertSame(BirEmployeeResolver::MATCHED, $result['status']);
    }

    public function test_a_separated_employee_is_still_findable(): void
    {
        // A 2316 is issued to people who left during the year — excluding
        // them would break the ordinary year-end case.
        $result = $this->resolver->resolve('Pedro Santos');

        $this->assertSame(BirEmployeeResolver::MATCHED, $result['status']);
        $this->assertSame('inactive', $result['employee']->status);
    }

    public function test_candidates_show_employment_status(): void
    {
        $this->employee('EMP100', 'Maria', 'Santos');

        // A surname alone is genuinely ambiguous: it matches the separated
        // Pedro Santos and the active Maria Santos.
        $result = $this->resolver->resolve('Santos');

        $this->assertSame(BirEmployeeResolver::AMBIGUOUS, $result['status']);
        $this->assertContains('inactive', array_column($result['candidates'], 'status'));
        $this->assertContains('active', array_column($result['candidates'], 'status'));
    }

    public function test_an_unknown_name_is_reported_not_found(): void
    {
        $result = $this->resolver->resolve('Bartholomew Fitzgerald');

        $this->assertSame(BirEmployeeResolver::NOT_FOUND, $result['status']);
        $this->assertEmpty($result['candidates']);
    }

    public function test_an_empty_query_is_not_a_match(): void
    {
        $result = $this->resolver->resolve('   ');

        $this->assertSame(BirEmployeeResolver::NOT_FOUND, $result['status']);
    }

    public function test_a_tin_finds_the_employee_however_it_is_written(): void
    {
        $this->employee('EMP200', 'Maria', 'Santos')->update(['tin_number' => '920-919-920-00001']);

        foreach (['920-919-920-00001', '920919920 00001', '920-919-920-001', '920 919 920', '92091992000001'] as $tin) {
            $this->assertSame('EMP200', $this->resolver->resolve($tin)['employee']?->employee_id, $tin);
        }
        $this->assertSame(BirEmployeeResolver::NOT_FOUND, $this->resolver->resolve('920-919-920-00002')['status'], 'A different branch is someone else.');
        $this->assertSame(BirEmployeeResolver::NOT_FOUND, $this->resolver->byTin('111-111-111-000')['status']);
    }

    public function test_a_name_in_a_sentence_is_found_and_a_lone_first_name_only_where_a_name_belongs(): void
    {
        $this->employee('EMP200', 'Grace', 'Lim');

        $this->assertSame('EMP200', $this->resolver->mentionedIn('edit 2316 item #6 for grace lim, change it to …')['employee']?->employee_id);
        $this->assertSame('EMP200', $this->resolver->mentionedIn("what is item 6 on Grace's 2316 for 2026")['employee']?->employee_id);
        $this->assertSame('EMP200', $this->resolver->mentionedIn('item 5 for EMP200')['employee']?->employee_id);
        $this->assertSame('EMP004', $this->resolver->mentionedIn('2316 for ana garcai 2026')['employee']?->employee_id, 'Typos after "for" are forgiven.');

        $this->assertSame(BirEmployeeResolver::NOT_NAMED, $this->resolver->mentionedIn('by the grace of the 1601-C for September 2026')['status']);
        $this->assertSame(BirEmployeeResolver::AMBIGUOUS, $this->resolver->mentionedIn('the 2316 for juan 2026')['status']);
    }
}
