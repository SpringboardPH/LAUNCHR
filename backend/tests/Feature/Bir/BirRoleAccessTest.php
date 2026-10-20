<?php

namespace Tests\Feature\Bir;

use App\Models\BirFormDraft;
use App\Models\User;
use App\Services\BIR\Llm\FakeLlmClient;
use App\Services\BIR\Llm\LlmClientInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\EnablesBirForms;
use Tests\TestCase;

/**
 * Every role against every /bir action (contract §1, §5, §6), as one table. Allowed means the
 * exact status below, which may still be a business refusal (422 for a form with unanswered
 * boxes); refused means 403 and nothing changed. Employees are refused everywhere; approve,
 * reject, finalize, signing and editing a pending form are accounting-only.
 *
 * The form is a real 1601-C made through POST /bir/drafts by a separate accounting preparer,
 * so the caller is never the preparer and a 403 on approve can only mean the role rule.
 */
class BirRoleAccessTest extends TestCase
{
    use EnablesBirForms;
    use RefreshDatabase;

    private const ALL = ['admin', 'hr', 'accounting'];

    private const ACCOUNTING = ['accounting'];

    private const ADMIN = ['admin'];

    /**
     * action => [method, path ({id} is the form), form status before the call, body,
     *            roles allowed, status expected when allowed].
     * A null status means no form is needed (it is still made, so "nothing changed" can be checked).
     */
    private const ACTIONS = [
        'config' => ['GET', '/api/bir/config', null, [], self::ALL, 200],
        'index' => ['GET', '/api/bir/drafts', null, [], self::ALL, 200],
        'show' => ['GET', '/api/bir/drafts/{id}', 'draft', [], self::ALL, 200],
        // 2026-08: formIn() already made the 2026-09 form, and a second one for a month is 409.
        'store' => ['POST', '/api/bir/drafts', null, ['form_type' => '1601-C', 'period' => '2026-08'], self::ALL, 201],
        'update a draft' => ['PUT', '/api/bir/drafts/{id}', 'draft', ['fields' => ['surcharge' => '1000.00']], self::ALL, 200],
        'update a pending form' => ['PUT', '/api/bir/drafts/{id}', 'pending', ['fields' => ['surcharge' => '1000.00']], self::ACCOUNTING, 200],
        'update an approved form' => ['PUT', '/api/bir/drafts/{id}', 'approved', ['fields' => ['surcharge' => '1000.00']], self::ACCOUNTING, 200],
        'void' => ['POST', '/api/bir/drafts/{id}/void', 'draft', ['reason' => 'Made by mistake'], self::ALL, 200],
        'history' => ['GET', '/api/bir/drafts/{id}/history', 'draft', [], self::ALL, 200],
        // 422: a fresh 1601-C still has unanswered required boxes, so validation refuses it.
        'submit' => ['POST', '/api/bir/drafts/{id}/submit', 'draft', [], self::ALL, 422],
        'approve' => ['POST', '/api/bir/drafts/{id}/approve', 'pending', [], self::ACCOUNTING, 422],
        'reject' => ['POST', '/api/bir/drafts/{id}/reject', 'pending', ['reason' => 'Recheck item 25'], self::ACCOUNTING, 200],
        'finalize' => ['POST', '/api/bir/drafts/{id}/finalize', 'approved', [], self::ACCOUNTING, 200],
        'revise' => ['POST', '/api/bir/drafts/{id}/revise', 'finalized', [], self::ALL, 201],
        // 422: the caller has no saved signature, so it can only mean the role got through.
        'sign' => ['POST', '/api/bir/drafts/{id}/sign', 'finalized', [], self::ACCOUNTING, 422],
        'wet signature' => ['POST', '/api/bir/drafts/{id}/wet-signature', 'finalized', [], self::ACCOUNTING, 200],
        'validate' => ['POST', '/api/bir/drafts/{id}/validate', 'draft', [], self::ALL, 200],
        'export' => ['GET', '/api/bir/drafts/{id}/export', 'draft', [], self::ALL, 200],
        'employees' => ['GET', '/api/bir/drafts/{id}/employees', 'draft', [], self::ALL, 200],
        // draft_id with no message only asks the next question; the model is never called.
        'chat' => ['POST', '/api/bir/chat', 'draft', ['draft_id' => '{id}'], self::ALL, 200],
        // The company password and the switch are admin-only. No password is saved here, so the
        // first set works and the switch refuses with "No company password is set up".
        'switch password' => ['PUT', '/api/bir/switch/password', null, ['password' => 'test-company-pass', 'password_confirmation' => 'test-company-pass'], self::ADMIN, 200],
        'switch' => ['PUT', '/api/bir/switch', null, ['enabled' => false, 'password' => 'test-company-pass'], self::ADMIN, 422],
    ];

    private const ROLES = ['admin', 'hr', 'accounting', 'employee'];

    protected function setUp(): void
    {
        parent::setUp();

        // Belt and braces: no row can ever reach Ollama.
        $this->app->instance(LlmClientInterface::class, (new FakeLlmClient)->willFail());
    }

    /** @return array<string, array{string, string}> "approve as hr" => ['approve', 'hr'] */
    public static function actionsByRole(): array
    {
        $cases = [];
        foreach (array_keys(self::ACTIONS) as $action) {
            foreach (self::ROLES as $role) {
                $cases["{$action} as {$role}"] = [$action, $role];
            }
        }

        return $cases;
    }

    #[DataProvider('actionsByRole')]
    public function test_each_role_gets_the_right_outcome(string $action, string $role): void
    {
        [$method, $path, $status, $body, $allowedRoles, $allowedStatus] = self::ACTIONS[$action];

        $id = $this->formIn($status ?? 'draft');
        $path = str_replace('{id}', (string) $id, $path);
        $body = array_map(fn ($value) => $value === '{id}' ? $id : $value, $body);
        $before = BirFormDraft::findOrFail($id);
        $countBefore = BirFormDraft::count();

        $caller = User::factory()->create(['role' => $role]);
        $response = $this->actingAs($caller)->json($method, $path, $body);

        if (in_array($role, $allowedRoles, true)) {
            $response->assertStatus($allowedStatus);

            return;
        }

        $response->assertForbidden()->assertJson(['success' => false]);

        $after = BirFormDraft::findOrFail($id);
        $this->assertSame($countBefore, BirFormDraft::count(), 'A refused call created a form.');
        $this->assertSame($before->status, $after->status, 'A refused call moved the form.');
        $this->assertEquals($before->fields, $after->fields, 'A refused call changed the form.');
        $this->assertSame($before->approved_by, $after->approved_by, 'A refused call set an approver.');
    }

    public function test_the_route_role_check_comes_before_not_found(): void
    {
        $missing = 999999;
        $hr = User::factory()->create(['role' => 'hr']);
        $accounting = User::factory()->create(['role' => 'accounting']);

        // approve is refused by the route, so HR never learns whether the form exists.
        $this->actingAs($hr)->postJson("/api/bir/drafts/{$missing}/approve")->assertForbidden();
        $this->actingAs($accounting)->postJson("/api/bir/drafts/{$missing}/approve")->assertNotFound();
        // PUT checks the role in code, after looking the form up: 404 first.
        $this->actingAs($hr)->putJson("/api/bir/drafts/{$missing}", ['fields' => ['surcharge' => '1000.00']])->assertNotFound();
    }

    /** A real 1601-C made by an accounting preparer, then moved straight to a status for the test. */
    private function formIn(string $status): int
    {
        $preparer = User::factory()->create(['role' => 'accounting']);
        $id = $this->actingAs($preparer)
            ->postJson('/api/bir/drafts', ['form_type' => '1601-C', 'period' => '2026-09'])
            ->assertCreated()
            ->json('data.id');

        BirFormDraft::whereKey($id)->update(['status' => $status]);

        return $id;
    }
}
