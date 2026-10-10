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
use Tests\TestCase;

/**
 * GET /bir/drafts/{id}/export fills the official BIR PDF from a real draft. The figures
 * printed are the draft's own; until the form is approved every page says it is a draft.
 * Where each value lands on the page is checked by BirPdfLayoutTest and, in Week 7, by
 * printing it beside a blank official form.
 */
class BirPdfExportTest extends TestCase
{
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

    private function pages(string $pdf): int
    {
        return (new Fpdi)->setSourceFile(StreamReader::createByString($pdf));
    }

    private function draft(string $formType): int
    {
        $employee = Employee::create([
            'employee_id' => 'EMP-PDF', 'first_name' => 'Bir', 'last_name' => 'Tester',
            'email' => 'pdf@example.com', 'position' => 'Staff', 'hire_date' => '2025-03-01',
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
