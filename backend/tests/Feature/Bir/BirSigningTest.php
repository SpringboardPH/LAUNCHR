<?php

namespace Tests\Feature\Bir;

use App\Models\BirFormDraft;
use App\Models\BirFormSignature;
use App\Models\Employee;
use App\Models\User;
use App\Services\BIR\BirFormSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\EnablesBirForms;
use Tests\TestCase;

/**
 * The employer signs a finalized form, or marks it for a wet signature (contract:
 * docs/bir-api-contract.md). Accounting-only. Signing never changes the form's fields or status,
 * freezes a copy of the signer's image, and a correction starts unsigned. Every image is a
 * generated blank one; no real signature is ever used.
 */
class BirSigningTest extends TestCase
{
    use EnablesBirForms;
    use RefreshDatabase;

    private User $accounting;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->accounting = User::factory()->create(['role' => 'accounting']);
    }

    public function test_accounting_signs_a_finalized_1601c(): void
    {
        $this->saveSignature($this->accounting);
        $id = $this->form('1601-C', 'finalized');

        $this->actingAs($this->accounting)->postJson("/api/bir/drafts/{$id}/sign")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.signing.method', 'digital')
            ->assertJsonPath('data.signing.employer.name', $this->accounting->name)
            ->assertJsonPath('data.signing.employee', null)
            ->assertJsonPath('data.signing.complete', true);

        $signature = BirFormSignature::where('bir_form_draft_id', $id)->firstOrFail();
        $this->assertSame('employer', $signature->role);
        $this->assertSame($this->accounting->id, $signature->user_id);
        $this->assertSame(BirFormSigner::fieldsHash(BirFormDraft::find($id)->fields), $signature->fields_hash);
        $this->assertNotNull($signature->signed_at);
        Storage::disk('local')->assertExists($signature->image_path);
    }

    public function test_accounting_signs_a_finalized_2316_and_it_waits_for_the_employee(): void
    {
        $this->saveSignature($this->accounting);
        $id = $this->form('2316', 'finalized');

        $this->actingAs($this->accounting)->postJson("/api/bir/drafts/{$id}/sign")
            ->assertOk()
            ->assertJsonPath('data.signing.method', 'digital')
            ->assertJsonPath('data.signing.employer.name', $this->accounting->name)
            ->assertJsonPath('data.signing.employee', null)
            ->assertJsonPath('data.signing.complete', false);
    }

    public function test_a_missing_form_is_404(): void
    {
        $this->saveSignature($this->accounting);

        $this->actingAs($this->accounting)->postJson('/api/bir/drafts/999999/sign')->assertNotFound();
        $this->actingAs($this->accounting)->postJson('/api/bir/drafts/999999/wet-signature')->assertNotFound();
    }

    public function test_only_a_finalized_form_can_be_signed(): void
    {
        $this->saveSignature($this->accounting);

        foreach (['draft', 'pending', 'approved'] as $status) {
            $id = $this->form('1601-C', $status);

            foreach (['sign', 'wet-signature'] as $action) {
                $this->actingAs($this->accounting)->postJson("/api/bir/drafts/{$id}/{$action}")
                    ->assertStatus(400)
                    ->assertJson(['success' => false, 'message' => 'Only a finalized form can be signed']);
            }
            $this->assertNull(BirFormDraft::find($id)->signing_method);
        }

        $this->assertSame(0, BirFormSignature::count());
    }

    public function test_the_employer_cannot_sign_twice(): void
    {
        $this->saveSignature($this->accounting);
        $id = $this->form('1601-C', 'finalized');

        $this->actingAs($this->accounting)->postJson("/api/bir/drafts/{$id}/sign")->assertOk();
        $this->actingAs($this->accounting)->postJson("/api/bir/drafts/{$id}/sign")
            ->assertStatus(409)
            ->assertJson(['success' => false]);

        // Another accounting user can't add a second employer signature either.
        $other = User::factory()->create(['role' => 'accounting']);
        $this->saveSignature($other);
        $this->actingAs($other)->postJson("/api/bir/drafts/{$id}/sign")->assertStatus(409);

        $this->assertSame(1, BirFormSignature::where('bir_form_draft_id', $id)->count());
    }

    public function test_signing_without_a_saved_signature_is_422(): void
    {
        $id = $this->form('1601-C', 'finalized');

        $this->actingAs($this->accounting)->postJson("/api/bir/drafts/{$id}/sign")
            ->assertStatus(422)
            ->assertJson(['success' => false, 'message' => 'Upload your signature first']);

        $this->assertSame(0, BirFormSignature::count());
        $this->assertNull(BirFormDraft::find($id)->signing_method);
    }

    public function test_hr_admin_and_employees_cannot_sign_or_mark_a_wet_signature(): void
    {
        $id = $this->form('1601-C', 'finalized');

        foreach (['hr', 'admin', 'employee'] as $role) {
            $caller = User::factory()->create(['role' => $role]);
            $this->saveSignature($caller);

            foreach (['sign', 'wet-signature'] as $action) {
                $this->actingAs($caller)->postJson("/api/bir/drafts/{$id}/{$action}")->assertForbidden();
            }
        }

        $this->assertSame(0, BirFormSignature::count());
        $this->assertNull(BirFormDraft::find($id)->signing_method);
    }

    public function test_both_routes_are_refused_while_the_switch_is_off(): void
    {
        $this->saveSignature($this->accounting);
        $id = $this->form('1601-C', 'finalized');
        \App\Models\SystemSettings::set('bir_forms_enabled', false, null, 'boolean');

        foreach (['sign', 'wet-signature'] as $action) {
            $this->actingAs($this->accounting)->postJson("/api/bir/drafts/{$id}/{$action}")->assertForbidden();
        }

        $this->assertSame(0, BirFormSignature::count());
        $this->assertNull(BirFormDraft::find($id)->signing_method);
    }

    public function test_a_wet_signature_marks_the_form_complete_and_stores_nothing(): void
    {
        $id = $this->form('2316', 'finalized');

        $this->actingAs($this->accounting)->postJson("/api/bir/drafts/{$id}/wet-signature")
            ->assertOk()
            ->assertJsonPath('data.signing.method', 'paper')
            ->assertJsonPath('data.signing.employer', null)
            ->assertJsonPath('data.signing.employee', null)
            ->assertJsonPath('data.signing.complete', true);

        // Pressing it again is not an error.
        $this->actingAs($this->accounting)->postJson("/api/bir/drafts/{$id}/wet-signature")->assertOk();

        $this->assertSame(0, BirFormSignature::count());
        $this->assertSame([], Storage::disk('local')->allFiles('bir-signatures'));
    }

    public function test_a_form_marked_for_a_wet_signature_cannot_be_signed_digitally(): void
    {
        $this->saveSignature($this->accounting);
        $id = $this->form('1601-C', 'finalized');

        $this->actingAs($this->accounting)->postJson("/api/bir/drafts/{$id}/wet-signature")->assertOk();
        $this->actingAs($this->accounting)->postJson("/api/bir/drafts/{$id}/sign")
            ->assertStatus(400)
            ->assertJson(['success' => false, 'message' => 'This form is signed by hand on paper']);

        $this->assertSame(0, BirFormSignature::count());
    }

    public function test_a_signed_form_cannot_be_marked_for_a_wet_signature(): void
    {
        $this->saveSignature($this->accounting);
        $id = $this->form('1601-C', 'finalized');

        $this->actingAs($this->accounting)->postJson("/api/bir/drafts/{$id}/sign")->assertOk();
        $this->actingAs($this->accounting)->postJson("/api/bir/drafts/{$id}/wet-signature")
            ->assertStatus(409)
            ->assertJson(['success' => false]);

        $this->assertSame('digital', BirFormDraft::find($id)->signing_method);
    }

    public function test_replacing_the_saved_signature_does_not_change_the_frozen_copy(): void
    {
        $this->saveSignature($this->accounting, 600, 200);
        $id = $this->form('1601-C', 'finalized');
        $this->actingAs($this->accounting)->postJson("/api/bir/drafts/{$id}/sign")->assertOk();

        $frozen = BirFormSignature::where('bir_form_draft_id', $id)->value('image_path');
        $before = Storage::disk('local')->get($frozen);

        $this->saveSignature($this->accounting, 900, 300);
        $this->actingAs($this->accounting)->deleteJson('/api/me/signature')->assertOk();

        Storage::disk('local')->assertExists($frozen);
        $this->assertSame($before, Storage::disk('local')->get($frozen));
        $this->assertSame($frozen, BirFormSignature::where('bir_form_draft_id', $id)->value('image_path'));
    }

    public function test_a_correction_of_a_signed_form_starts_unsigned(): void
    {
        $this->saveSignature($this->accounting);
        $id = $this->form('2316', 'finalized');
        $this->actingAs($this->accounting)->postJson("/api/bir/drafts/{$id}/sign")->assertOk();

        $revisionId = $this->actingAs($this->accounting)->postJson("/api/bir/drafts/{$id}/revise")
            ->assertCreated()
            ->assertJsonPath('data.signing', ['method' => null, 'employer' => null, 'employee' => null, 'complete' => false])
            ->json('data.id');

        $this->assertSame(0, BirFormSignature::where('bir_form_draft_id', $revisionId)->count());
        $this->assertNull(BirFormDraft::find($revisionId)->signing_method);
        // The filed version keeps its signature.
        $this->assertSame(1, BirFormSignature::where('bir_form_draft_id', $id)->count());
        $this->assertSame('digital', BirFormDraft::find($id)->signing_method);
    }

    public function test_signing_leaves_the_fields_and_status_alone(): void
    {
        $this->saveSignature($this->accounting);
        $id = $this->form('1601-C', 'finalized');
        $before = BirFormDraft::find($id);

        $this->actingAs($this->accounting)->postJson("/api/bir/drafts/{$id}/sign")->assertOk();

        $after = BirFormDraft::find($id);
        $this->assertSame('finalized', $after->status);
        $this->assertEquals($before->fields, $after->fields);
        $this->assertEquals($before->validation_errors, $after->validation_errors);
        $this->assertSame($before->approved_by, $after->approved_by);
    }

    public function test_the_api_never_shows_image_paths_ip_user_agent_or_hash(): void
    {
        $this->saveSignature($this->accounting);
        $id = $this->form('1601-C', 'finalized');

        $responses = [
            $this->actingAs($this->accounting)->postJson("/api/bir/drafts/{$id}/sign"),
            $this->actingAs($this->accounting)->getJson("/api/bir/drafts/{$id}"),
            $this->actingAs($this->accounting)->getJson('/api/bir/drafts'),
        ];
        $signature = BirFormSignature::where('bir_form_draft_id', $id)->firstOrFail();

        foreach ($responses as $response) {
            $response->assertOk();
            $body = $response->getContent();
            foreach (['image_path', 'bir-signatures', 'fields_hash', 'user_agent', $signature->fields_hash] as $secret) {
                $this->assertStringNotContainsString($secret, $body);
            }
        }
        $this->assertSame(['name', 'signed_at'], array_keys($responses[1]->json('data.signing.employer')));
    }

    public function test_an_unsigned_finalized_form_shows_signing_as_not_chosen(): void
    {
        $id = $this->form('1601-C', 'finalized');

        $this->actingAs($this->accounting)->getJson("/api/bir/drafts/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'finalized')
            ->assertJsonPath('data.signing', ['method' => null, 'employer' => null, 'employee' => null, 'complete' => false]);
    }

    private function saveSignature(User $user, int $width = 600, int $height = 200): void
    {
        $this->actingAs($user)
            ->post('/api/me/signature', ['signature' => UploadedFile::fake()->image('sig.png', $width, $height)], ['Accept' => 'application/json'])
            ->assertSuccessful();
    }

    /** A form saved straight in a status: signing only looks at the status, type and fields. */
    private function form(string $type, string $status): int
    {
        static $people = 0;
        $people++;

        $employee = $type === '2316' ? Employee::create([
            'employee_id' => "EMP-SIGN-{$people}",
            'first_name' => 'Sign',
            'last_name' => "Tester{$people}",
            'email' => "sign-{$people}@example.com",
            'position' => 'Staff',
            'hire_date' => '2024-01-15',
            'salary' => 30000,
            'status' => 'active',
            'rate_type' => 'monthly',
        ]) : null;

        return BirFormDraft::create([
            'form_type' => $type,
            'period' => $type === '2316' ? '2026' : '2026-09',
            'employee_id' => $employee?->id,
            'status' => $status,
            'version' => 1,
            'fields' => ['total_amount_remitted' => ['value' => '1500.00', 'origin' => 'payroll', 'edited' => false]],
            'validation_errors' => [],
            'prepared_by' => $this->accounting->id,
        ])->id;
    }
}
