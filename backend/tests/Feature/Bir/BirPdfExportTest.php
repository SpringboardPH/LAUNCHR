<?php

namespace Tests\Feature\Bir;

use App\Models\BirFormDraft;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\User;
use App\Services\BIR\BirPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Tests\Concerns\EnablesBirForms;
use Tests\TestCase;

/**
 * GET /bir/drafts/{id}/export fills the official BIR PDF from a real draft. The figures
 * printed are the draft's own; until the form is approved every page says it is a draft.
 * Where each value lands on the page is checked by BirPdfLayoutTest and, in Week 7, by
 * printing it beside a blank official form.
 */
class BirPdfExportTest extends TestCase
{
    use EnablesBirForms;
    use RefreshDatabase;

    private User $accounting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accounting = User::factory()->create(['role' => 'accounting']);
    }

    public function test_a_2316_exports_as_the_one_page_official_form_marked_as_a_draft(): void
    {
        $id = $this->draft('2316');

        $response = $this->actingAs($this->accounting)->get("/api/bir/drafts/{$id}/export")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="BIR-2316-2026-EMP-PDF-v1-DRAFT.pdf"');

        $this->assertSame(1, $this->pages($response->getContent()));
        $this->assertStringContainsString("DRAFT #{$id}", $this->text($id), 'Every page says it is a draft.');
        $this->assertStringContainsString('NOT FOR FILING', $this->text($id));
    }

    public function test_a_1601c_exports_both_pages_of_the_official_form(): void
    {
        $id = $this->draft('1601-C');

        $response = $this->actingAs($this->accounting)->get("/api/bir/drafts/{$id}/export")->assertOk();

        $this->assertSame(2, $this->pages($response->getContent()));
    }

    public function test_the_figures_printed_are_the_drafts_own_even_after_payroll_changes(): void
    {
        $id = $this->draft('2316');
        $gross = BirFormDraft::findOrFail($id)->fields['gross_compensation_present']['value'];

        Payroll::query()->update(['gross_pay' => 99999]);

        $text = $this->text($id);
        $this->assertStringContainsString('(' . number_format((float) $gross, 2) . ')', $text);
        $this->assertStringNotContainsString('199,998.00', $text);
    }

    public function test_an_approved_form_is_printed_without_the_draft_mark(): void
    {
        $id = $this->draft('2316');
        BirFormDraft::whereKey($id)->update(['status' => 'approved']);

        $this->actingAs($this->accounting)->get("/api/bir/drafts/{$id}/export")
            ->assertHeader('Content-Disposition', 'attachment; filename="BIR-2316-2026-EMP-PDF-v1.pdf"');
        $this->assertStringNotContainsString('NOT FOR FILING', $this->text($id));
    }

    public function test_the_browser_is_allowed_to_read_the_file_name(): void
    {
        $id = $this->draft('2316');

        $exposed = $this->actingAs($this->accounting)
            ->get("/api/bir/drafts/{$id}/export", ['Origin' => 'http://localhost:5173'])
            ->assertOk()
            ->headers->get('Access-Control-Expose-Headers');

        $this->assertStringContainsString('Content-Disposition', (string) $exposed,
            'Without this the frontend cannot see the official file name.');
    }

    public function test_a_new_version_of_a_filed_2316_says_in_red_that_it_was_filed_before(): void
    {
        $v1 = $this->draft('2316');
        BirFormDraft::whereKey($v1)->update(['status' => 'finalized']);
        $v2 = $this->actingAs($this->accounting)->postJson("/api/bir/drafts/{$v1}/revise")->assertCreated()->json('data.id');

        $this->assertStringContainsString("THIS FORM HAS ALREADY BEEN FILED AS FORM #{$v1}. THIS IS VERSION 2, FORM #{$v1}v2.", $this->text($v2));
        BirFormDraft::whereKey($v2)->update(['status' => 'approved']);
        $this->assertStringContainsString('THIS FORM HAS ALREADY BEEN FILED', $this->text($v2), 'Until this version is filed itself.');

        BirFormDraft::whereKey($v2)->update(['status' => 'finalized']);
        $this->assertStringNotContainsString('ALREADY BEEN FILED', $this->text($v2), 'Filed itself: it is the filed one now.');
        $this->assertStringNotContainsString('ALREADY BEEN FILED', $this->text($v1));
    }

    public function test_long_text_is_never_cut_off_and_page_2_of_the_1601c_has_the_tin_and_name(): void
    {
        $name = 'SPRINGBOARD PHILIPPINES INFORMATION TECHNOLOGY SOLUTIONS INC.';
        $address = 'UNIT 1203 TOWER ONE, AYALA TRIANGLE GARDENS, PASEO DE ROXAS CORNER MAKATI AVENUE, BRGY. SAN LORENZO, MAKATI CITY';
        $id = $this->draft('1601-C');
        $this->setFields($id, ['company_name' => $name, 'company_address' => $address, 'company_tin' => '987-654-321-00000', 'rdo_code' => '21A']);
        $pdf = $this->text($id);
        [$page1, $page2] = $this->pageStreams($pdf);

        // Too long for its cells: written small in full, never cut off. The name is on both pages.
        $this->assertStringContainsString("({$address}) Tj", $page1);
        $this->assertStringContainsString("({$name}) Tj", $page1);
        $this->assertStringContainsString("({$name}) Tj", $page2, 'Page 2 repeats the withholding agent\'s name.');
        foreach (str_split('98765432100000') as $digit) {
            $this->assertStringContainsString("({$digit}) Tj", $page2, 'Page 2 repeats the TIN.');
        }
        $this->assertStringContainsString('(A) Tj', $page1, 'RDO 21A keeps its letter.');

        // A short address breaks between words from one row of cells to the next.
        $this->setFields($id, ['company_address' => '1 AYALA AVENUE, BRGY. SAN LORENZO, MAKATI CITY, METRO MANILA']);
        $this->assertStringNotContainsString('AYALA AVENUE', $this->text($id), 'Short enough for the cells: one letter per cell.');

        // A 2316 address too long for one line goes onto two.
        $two = $this->draft('2316', 'EMP-PDF2');
        $this->setFields($two, ['employee_registered_address' => '22 MABINI STREET, BRGY. POBLACION, SAN JOSE DEL MONTE CITY, BULACAN PROVINCE']);
        $this->assertMatchesRegularExpression('/\(22 MABINI STREET, BRGY\. POBLACION, SAN\) Tj.*\(JOSE DEL MONTE CITY, BULACAN PROVINCE\) Tj/s', $this->text($two));
    }

    public function test_an_unknown_draft_is_a_404_and_employees_cannot_export(): void
    {
        $this->actingAs($this->accounting)->getJson('/api/bir/drafts/999/export')->assertNotFound();

        $id = $this->draft('2316');
        $employee = User::factory()->create(['role' => 'employee']);
        $this->actingAs($employee)->getJson("/api/bir/drafts/{$id}/export")->assertForbidden();
    }

    /** The PDF's drawing commands, uncompressed, so printed text can be searched. */
    private function text(int $id): string
    {
        return (new BirPdfService)->render(BirFormDraft::with('employee')->findOrFail($id), compress: false);
    }

    /** Each page's drawing commands, in page order (FPDF writes one content stream per page). */
    private function pageStreams(string $pdf): array
    {
        preg_match_all('/stream\r?\n(.*?)endstream/s', $pdf, $m);

        return array_values(array_filter($m[1], fn (string $s) => str_contains($s, ' Tj')));
    }

    private function setFields(int $id, array $values): void
    {
        $draft = BirFormDraft::findOrFail($id);
        $fields = $draft->fields;
        foreach ($values as $key => $value) {
            $fields[$key] = ['value' => $value, 'origin' => 'user', 'edited' => false];
        }
        $draft->update(['fields' => $fields]);
    }

    private function pages(string $pdf): int
    {
        return (new Fpdi)->setSourceFile(StreamReader::createByString($pdf));
    }

    private function draft(string $formType, string $code = 'EMP-PDF'): int
    {
        $employee = Employee::create([
            'employee_id' => $code, 'first_name' => 'Bir', 'last_name' => 'Tester',
            'email' => strtolower($code) . '@example.com', 'position' => 'Staff', 'hire_date' => '2025-03-01',
            'salary' => 30000, 'status' => 'active', 'rate_type' => 'monthly', 'tin_number' => '123-456-789-000',
        ]);

        foreach ([['2026-09-01', '2026-09-15'], ['2026-09-16', '2026-09-30']] as [$start, $end]) {
            Payroll::create([
                'employee_id' => $employee->id, 'cutoff_start' => $start, 'cutoff_end' => $end,
                'base_salary' => 30000, 'gross_pay' => 15000,
                'deductions' => ['SSS EE Contribution' => 700, 'Withholding Tax' => 300],
                'allowances' => [], 'status' => 'finalized',
            ]);
        }

        $body = $formType === '2316'
            ? ['form_type' => '2316', 'period' => '2026', 'employee_id' => $employee->id]
            : ['form_type' => '1601-C', 'period' => '2026-09'];

        return $this->actingAs($this->accounting)->postJson('/api/bir/drafts', $body)->assertCreated()->json('data.id');
    }
}
