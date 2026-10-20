<?php

namespace Tests\Feature\Bir;

use App\Mail\BirFormToSignMail;
use App\Models\BirFormDraft;
use App\Models\BirFormSignature;
use App\Models\Employee;
use App\Models\SystemSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\EnablesBirForms;
use Tests\TestCase;

/**
 * The employee signs their own 2316 after the employer (contract: docs/bir-api-contract.md).
 * They are emailed when the employer signs, see only their own forms (anyone else's is 404),
 * and can open the PDF before signing. Every image is a generated blank one.
 */
class BirEmployeeSigningTest extends TestCase
{
    use EnablesBirForms;
    use RefreshDatabase;

    private User $accounting;

    private int $people = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Mail::fake();
        $this->accounting = User::factory()->create(['role' => 'accounting']);
        $this->saveSignature($this->accounting);
    }

    public function test_the_employer_signing_a_2316_emails_that_employee(): void
    {
        [$user, $employee] = $this->employeeWithLogin();
        $id = $this->form2316($employee);

        $this->employerSigns($id);

        Mail::assertQueued(BirFormToSignMail::class, 1);
        Mail::assertQueued(BirFormToSignMail::class, function (BirFormToSignMail $mail) use ($employee, $id) {
            return $mail->hasTo($employee->email)
                && $mail->draft->id === $id
                && $mail->envelope()->subject === 'Your BIR Form 2316 for 2026 is ready to sign';
        });
        // The address on the employee record, not the login's.
        Mail::assertNotQueued(BirFormToSignMail::class, fn (BirFormToSignMail $mail) => $mail->hasTo($user->email));
    }

    public function test_the_email_links_to_the_forms_to_sign_page(): void
    {
        config(['app.frontend_url' => 'https://hr.example.test/']);
        [, $employee] = $this->employeeWithLogin();
        $id = $this->form2316($employee);

        $mail = new BirFormToSignMail(BirFormDraft::with('employee')->find($id));

        $this->assertSame('https://hr.example.test/my-bir-forms', $mail->content()->with['link']);
        $this->assertSame('2026', $mail->content()->with['year']);
    }

    public function test_no_email_for_a_1601c(): void
    {
        $id = BirFormDraft::create([
            'form_type' => '1601-C', 'period' => '2026-09', 'status' => 'finalized', 'version' => 1,
            'fields' => [], 'validation_errors' => [], 'prepared_by' => $this->accounting->id,
        ])->id;

        $this->employerSigns($id);

        Mail::assertNothingQueued();
    }

    public function test_no_email_when_the_employee_has_no_email_or_no_login(): void
    {
        [, $noEmail] = $this->employeeWithLogin(['email' => '']);
        $noLogin = $this->employee(null);

        $this->employerSigns($this->form2316($noEmail));
        $this->employerSigns($this->form2316($noLogin));

        Mail::assertNothingQueued();
    }

    public function test_the_employee_sees_opens_and_signs_their_2316(): void
    {
        [$user, $employee] = $this->employeeWithLogin();
        $id = $this->form2316($employee);
        $this->employerSigns($id);
        $this->saveSignature($user);

        $this->actingAs($user)->getJson('/api/my/bir-forms')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.label', "#{$id}")
            ->assertJsonPath('data.0.tax_year', '2026')
            ->assertJsonPath('data.0.signing.employer.name', $this->accounting->name)
            ->assertJsonPath('data.0.needs_my_signature', true);

        $pdf = $this->actingAs($user)->get("/api/my/bir-forms/{$id}/pdf")->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->actingAs($user)->postJson("/api/my/bir-forms/{$id}/sign")
            ->assertOk()
            ->assertJsonPath('data.signing.employee.name', $user->name)
            ->assertJsonPath('data.signing.complete', true)
            ->assertJsonPath('data.needs_my_signature', false);

        $signature = BirFormSignature::where('bir_form_draft_id', $id)->where('role', 'employee')->firstOrFail();
        $this->assertSame($user->id, $signature->user_id);
        Storage::disk('local')->assertExists($signature->image_path);
        $this->assertSame('finalized', BirFormDraft::find($id)->status);
    }

    public function test_a_form_the_employer_has_not_signed_is_hidden_and_cannot_be_signed(): void
    {
        [$user, $employee] = $this->employeeWithLogin();
        $id = $this->form2316($employee);
        $this->saveSignature($user);

        $this->actingAs($user)->getJson('/api/my/bir-forms')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($user)->get("/api/my/bir-forms/{$id}/pdf")->assertNotFound();
        $this->actingAs($user)->postJson("/api/my/bir-forms/{$id}/sign")
            ->assertStatus(400)
            ->assertJson(['success' => false, 'message' => 'The employer has to sign this form first']);

        $this->assertSame(0, BirFormSignature::count());
    }

    public function test_a_form_marked_for_a_wet_signature_cannot_be_signed_by_the_employee(): void
    {
        [$user, $employee] = $this->employeeWithLogin();
        $id = $this->form2316($employee);
        $this->actingAs($this->accounting)->postJson("/api/bir/drafts/{$id}/wet-signature")->assertOk();
        $this->saveSignature($user);

        $this->actingAs($user)->getJson('/api/my/bir-forms')->assertJsonCount(0, 'data');
        $this->actingAs($user)->postJson("/api/my/bir-forms/{$id}/sign")
            ->assertStatus(400)
            ->assertJson(['message' => 'This form is signed by hand on paper']);
        Mail::assertNothingQueued();
    }

    public function test_the_employee_cannot_sign_twice(): void
    {
        [$user, $employee] = $this->employeeWithLogin();
        $id = $this->form2316($employee);
        $this->employerSigns($id);
        $this->saveSignature($user);

        $this->actingAs($user)->postJson("/api/my/bir-forms/{$id}/sign")->assertOk();
        $this->actingAs($user)->postJson("/api/my/bir-forms/{$id}/sign")
            ->assertStatus(409)
            ->assertJson(['success' => false]);

        $this->assertSame(1, BirFormSignature::where('bir_form_draft_id', $id)->where('role', 'employee')->count());
    }

    public function test_signing_without_a_saved_signature_is_422(): void
    {
        [$user, $employee] = $this->employeeWithLogin();
        $id = $this->form2316($employee);
        $this->employerSigns($id);

        $this->actingAs($user)->postJson("/api/my/bir-forms/{$id}/sign")
            ->assertStatus(422)
            ->assertJson(['message' => 'Upload your signature first']);
    }

    public function test_another_employees_form_is_404(): void
    {
        [, $owner] = $this->employeeWithLogin();
        [$stranger] = $this->employeeWithLogin();
        $id = $this->form2316($owner);
        $this->employerSigns($id);
        $this->saveSignature($stranger);

        $this->actingAs($stranger)->getJson('/api/my/bir-forms')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($stranger)->get("/api/my/bir-forms/{$id}/pdf")->assertNotFound();
        $this->actingAs($stranger)->postJson("/api/my/bir-forms/{$id}/sign")
            ->assertNotFound()
            ->assertJson(['success' => false, 'message' => 'Form not found']);

        $this->assertNull(BirFormSignature::where('bir_form_draft_id', $id)->where('role', 'employee')->first());
    }

    public function test_hr_and_accounting_users_who_are_not_that_employee_get_404(): void
    {
        [, $owner] = $this->employeeWithLogin();
        $id = $this->form2316($owner);
        $this->employerSigns($id);

        $hr = User::factory()->create(['role' => 'hr']);
        $this->saveSignature($hr);

        foreach ([$hr, $this->accounting] as $caller) {
            $this->actingAs($caller)->get("/api/my/bir-forms/{$id}/pdf")->assertNotFound();
            $this->actingAs($caller)->postJson("/api/my/bir-forms/{$id}/sign")->assertNotFound();
        }

        $this->assertNull(BirFormSignature::where('bir_form_draft_id', $id)->where('role', 'employee')->first());
    }

    public function test_a_1601c_is_never_an_employees_form(): void
    {
        [$user] = $this->employeeWithLogin();
        $this->saveSignature($user);
        $id = BirFormDraft::create([
            'form_type' => '1601-C', 'period' => '2026-09', 'status' => 'finalized', 'version' => 1,
            'fields' => [], 'validation_errors' => [], 'prepared_by' => $this->accounting->id,
        ])->id;
        $this->employerSigns($id);

        $this->actingAs($user)->postJson("/api/my/bir-forms/{$id}/sign")->assertNotFound();
    }

    public function test_every_route_is_refused_while_the_switch_is_off(): void
    {
        [$user, $employee] = $this->employeeWithLogin();
        $id = $this->form2316($employee);
        $this->employerSigns($id);
        $this->saveSignature($user);
        SystemSettings::set('bir_forms_enabled', false, null, 'boolean');

        $this->actingAs($user)->getJson('/api/my/bir-forms')->assertForbidden();
        $this->actingAs($user)->get("/api/my/bir-forms/{$id}/pdf", ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($user)->postJson("/api/my/bir-forms/{$id}/sign")
            ->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'The BIR Form Assistant is not enabled']);

        $this->assertNull(BirFormSignature::where('bir_form_draft_id', $id)->where('role', 'employee')->first());
    }

    public function test_every_route_needs_a_login(): void
    {
        // setUp() acted as accounting to save a signature; drop that login first.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/my/bir-forms')->assertUnauthorized();
        $this->getJson('/api/my/bir-forms/1/pdf')->assertUnauthorized();
        $this->postJson('/api/my/bir-forms/1/sign')->assertUnauthorized();
    }

    private function employerSigns(int $id): void
    {
        $this->actingAs($this->accounting)->postJson("/api/bir/drafts/{$id}/sign")->assertOk();
    }

    private function saveSignature(User $user): void
    {
        $this->actingAs($user)
            ->post('/api/me/signature', ['signature' => UploadedFile::fake()->image('sig.png', 600, 200)], ['Accept' => 'application/json'])
            ->assertSuccessful();
    }

    /** @return array{0: User, 1: Employee} */
    private function employeeWithLogin(array $overrides = []): array
    {
        $user = User::factory()->create(['role' => 'employee']);

        return [$user, $this->employee($user, $overrides)];
    }

    private function employee(?User $user, array $overrides = []): Employee
    {
        $this->people++;

        return Employee::create($overrides + [
            'user_id' => $user?->id,
            'employee_id' => "EMP-ESIGN-{$this->people}",
            'first_name' => 'Sign',
            'last_name' => "Tester{$this->people}",
            'email' => "esign-{$this->people}@example.com",
            'position' => 'Staff',
            'hire_date' => '2024-01-15',
            'salary' => 30000,
            'status' => 'active',
            'rate_type' => 'monthly',
        ]);
    }

    /** A finalized 2316 saved straight in that status: signing only looks at status, type and owner. */
    private function form2316(Employee $employee): int
    {
        return BirFormDraft::create([
            'form_type' => '2316',
            'period' => '2026',
            'employee_id' => $employee->id,
            'status' => 'finalized',
            'version' => 1,
            'fields' => [],
            'validation_errors' => [],
            'prepared_by' => $this->accounting->id,
        ])->id;
    }
}
