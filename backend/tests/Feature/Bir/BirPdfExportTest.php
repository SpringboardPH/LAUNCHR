<?php

namespace Tests\Feature\Bir;

use App\Models\BirFormDraft;
use App\Models\BirFormSignature;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\User;
use App\Services\BIR\BirPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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

    public function test_e_signatures_are_printed_where_each_form_is_signed(): void
    {
        Storage::fake('local');
        // A 2316: the employer at item 53, the employee at item 54, each with the day they signed,
        // whatever date the box was given before.
        $id = $this->draft('2316');
        $this->setFields($id, ['employer_signature_date' => '2026-01-05']);
        $fives = substr_count($this->text($id), '(5) Tj');
        $this->signed($id, ['employer' => '2026-10-21 09:00:00', 'employee' => '2026-10-22 15:30:00']);
        $pdf = $this->text($id);
        $this->assertSame(2, $this->images($pdf), 'Both signatures are drawn.');
        $this->assertSame($fives - 1, substr_count($pdf, '(5) Tj'), 'The typed 01/05/2026 gave way to the signed 10/21/2026.');
        // Under each signature, the signer's profile name.
        $this->assertSame(2, substr_count($pdf, "({$this->accounting->name}) Tj"));
        // Nothing else about the signature is printed.
        foreach (['bir-signatures/', '203.0.113.9', 'TestAgent', str_repeat('f', 64)] as $hidden) {
            $this->assertStringNotContainsString($hidden, $pdf);
        }

        // A 1601-C: the employer only, and the date in the signature box.
        $form = $this->draft('1601-C', 'EMP-PDF2');
        $this->signed($form, ['employer' => '2026-10-21 09:00:00']);
        $pdf = $this->text($form);
        $this->assertSame(1, $this->images($pdf));
        $this->assertStringContainsString('(10/21/2026) Tj', $pdf);
        $this->assertStringContainsString("({$this->accounting->name}) Tj", $pdf);
    }

    public function test_a_line_with_no_e_signature_is_left_empty_for_a_pen(): void
    {
        Storage::fake('local');
        // Only the employer has signed: one signature, the employee's line stays empty.
        $id = $this->draft('2316');
        $this->signed($id, ['employer' => '2026-10-21 09:00:00']);
        $this->assertSame(1, $this->images($this->text($id)));

        // Marked for a wet signature: nothing printed, even if a signature row were there.
        BirFormDraft::whereKey($id)->update(['signing_method' => BirFormDraft::SIGN_PAPER]);
        $this->assertSame(0, $this->images($this->text($id)));

        // Not signed at all.
        $this->assertSame(0, $this->images($this->text($this->draft('1601-C', 'EMP-PDF3'))));
    }

    /** Signature rows for these roles, each with a small image on the private disk, as BirFormSigner leaves them. */
    private function signed(int $id, array $roles): void
    {
        BirFormDraft::whereKey($id)->update(['status' => 'finalized', 'signing_method' => BirFormDraft::SIGN_DIGITAL]);
        foreach ($roles as $role => $at) {
            $image = imagecreatetruecolor(300, 100);
            imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
            imageline($image, 10, 50, 290, 60, imagecolorallocate($image, 0, 0, 0));
            ob_start();
            $role === BirFormSignature::EMPLOYER ? imagepng($image) : imagejpeg($image);
            $path = "bir-signatures/{$id}-{$role}." . ($role === BirFormSignature::EMPLOYER ? 'png' : 'jpg');
            Storage::disk('local')->put($path, ob_get_clean());
            BirFormSignature::create([
                'bir_form_draft_id' => $id, 'role' => $role, 'user_id' => $this->accounting->id, 'image_path' => $path,
                'signed_at' => $at, 'ip' => '203.0.113.9', 'user_agent' => 'TestAgent', 'fields_hash' => str_repeat('f', 64),
            ]);
        }
    }

    /** How many images the pages draw. */
    private function images(string $pdf): int
    {
        return array_sum(array_map(fn (string $page) => preg_match_all('/\/I\d+ Do/', $page), $this->pageStreams($pdf)));
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
