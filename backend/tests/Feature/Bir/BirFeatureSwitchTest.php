<?php

namespace Tests\Feature\Bir;

use App\Models\BirFormDraft;
use App\Models\SystemSettings;
use App\Models\User;
use App\Services\BIR\Llm\FakeLlmClient;
use App\Services\BIR\Llm\LlmClientInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The bir_forms_enabled switch (contract §1, §6). Off, or no row at all, every /bir action except
 * GET /bir/config is 403 "The BIR Form Assistant is not enabled" for admin, HR and accounting, and
 * nothing changes. The role check still comes first, so an employee gets the role message.
 *
 * The company password and the switch (PUT /bir/switch/password, PUT /bir/switch) stay open too.
 *
 * Deliberately does not use EnablesBirForms: the switch starts off here, as in a fresh install.
 */
class BirFeatureSwitchTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'The BIR Form Assistant is not enabled';

    /**
     * action => [method, path ({id} is the form), form status before the call, body].
     * Each status is one the action would accept, so only the switch can explain a refusal.
     */
    private const ACTIONS = [
        'index' => ['GET', '/api/bir/drafts', 'draft', []],
        'show' => ['GET', '/api/bir/drafts/{id}', 'draft', []],
        'store' => ['POST', '/api/bir/drafts', 'draft', ['form_type' => '1601-C', 'period' => '2026-09']],
        'update a draft' => ['PUT', '/api/bir/drafts/{id}', 'draft', ['fields' => ['surcharge' => '1000.00']]],
        'update a pending form' => ['PUT', '/api/bir/drafts/{id}', 'pending', ['fields' => ['surcharge' => '1000.00']]],
        'void' => ['POST', '/api/bir/drafts/{id}/void', 'draft', ['reason' => 'Made by mistake']],
        'history' => ['GET', '/api/bir/drafts/{id}/history', 'draft', []],
        'submit' => ['POST', '/api/bir/drafts/{id}/submit', 'draft', []],
        'approve' => ['POST', '/api/bir/drafts/{id}/approve', 'pending', []],
        'reject' => ['POST', '/api/bir/drafts/{id}/reject', 'pending', ['reason' => 'Recheck item 25']],
        'finalize' => ['POST', '/api/bir/drafts/{id}/finalize', 'approved', []],
        'revise' => ['POST', '/api/bir/drafts/{id}/revise', 'finalized', []],
        'sign' => ['POST', '/api/bir/drafts/{id}/sign', 'finalized', []],
        'wet signature' => ['POST', '/api/bir/drafts/{id}/wet-signature', 'finalized', []],
        'validate' => ['POST', '/api/bir/drafts/{id}/validate', 'draft', []],
        'export' => ['GET', '/api/bir/drafts/{id}/export', 'draft', []],
        'employees' => ['GET', '/api/bir/drafts/{id}/employees', 'draft', []],
        'chat' => ['POST', '/api/bir/chat', 'draft', ['draft_id' => '{id}']],
    ];

    private const ROLES = ['admin', 'hr', 'accounting'];

    /** "no row": the setting was never saved. "row false": it was saved and turned off. */
    private const OFF_STATES = ['no row', 'row false'];

    protected function setUp(): void
    {
        parent::setUp();

        // No call here should ever reach Ollama.
        $this->app->instance(LlmClientInterface::class, (new FakeLlmClient)->willFail());
    }

    /** @return array<string, array{string, string, string}> "approve as hr, no row" => ['approve', 'hr', 'no row'] */
    public static function actionsByRoleWhileOff(): array
    {
        $cases = [];
        foreach (array_keys(self::ACTIONS) as $action) {
            foreach (self::ROLES as $role) {
                foreach (self::OFF_STATES as $off) {
                    $cases["{$action} as {$role}, {$off}"] = [$action, $role, $off];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('actionsByRoleWhileOff')]
    public function test_every_action_is_refused_while_the_switch_is_off(string $action, string $role, string $off): void
    {
        [$method, $path, $status, $body] = self::ACTIONS[$action];

        $id = $this->formIn($status);
        $path = str_replace('{id}', (string) $id, $path);
        $body = array_map(fn ($value) => $value === '{id}' ? $id : $value, $body);
        $this->switchOff($off);

        $before = BirFormDraft::findOrFail($id);
        $countBefore = BirFormDraft::count();

        $caller = User::factory()->create(['role' => $role]);
        $this->actingAs($caller)->json($method, $path, $body)
            ->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => self::MESSAGE]);

        $after = BirFormDraft::findOrFail($id);
        $this->assertSame($countBefore, BirFormDraft::count(), 'A refused call created a form.');
        $this->assertSame($before->status, $after->status, 'A refused call moved the form.');
        $this->assertEquals($before->fields, $after->fields, 'A refused call changed the form.');
        $this->assertEquals($before->validation_errors, $after->validation_errors, 'A refused call stored validation errors.');
        $this->assertSame($before->approved_by, $after->approved_by, 'A refused call set an approver.');
    }

    /** @return array<string, array{string, string}> */
    public static function rolesWhileOff(): array
    {
        $cases = [];
        foreach (self::ROLES as $role) {
            foreach (self::OFF_STATES as $off) {
                $cases["{$role}, {$off}"] = [$role, $off];
            }
        }

        return $cases;
    }

    #[DataProvider('rolesWhileOff')]
    public function test_config_stays_open_and_says_disabled_while_off(string $role, string $off): void
    {
        $this->switchOff($off);

        $this->actingAs(User::factory()->create(['role' => $role]))
            ->getJson('/api/bir/config')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.enabled', false);
    }

    /** The company password and the switch stay open while off, or BIR could never be turned on. */
    #[DataProvider('offStates')]
    public function test_an_admin_can_set_the_password_and_turn_it_on_while_off(string $off): void
    {
        $this->switchOff($off);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->putJson('/api/bir/switch/password', ['password' => 'test-company-pass', 'password_confirmation' => 'test-company-pass'])
            ->assertOk();

        $this->actingAs($admin)
            ->putJson('/api/bir/switch', ['enabled' => true, 'password' => 'test-company-pass'])
            ->assertOk()
            ->assertJsonPath('data.enabled', true);

        $this->actingAs($admin)->getJson('/api/bir/config')->assertOk()->assertJsonPath('data.enabled', true);
    }

    /** @return array<string, array{string}> */
    public static function offStates(): array
    {
        return array_combine(self::OFF_STATES, array_map(fn (string $off) => [$off], self::OFF_STATES));
    }

    public function test_with_the_switch_on_config_says_enabled_and_actions_work(): void
    {
        SystemSettings::set('bir_forms_enabled', true, null, 'boolean');
        $accounting = User::factory()->create(['role' => 'accounting']);

        $this->actingAs($accounting)->getJson('/api/bir/config')
            ->assertOk()
            ->assertJsonPath('data.enabled', true);

        $this->actingAs($accounting)
            ->postJson('/api/bir/drafts', ['form_type' => '1601-C', 'period' => '2026-09'])
            ->assertCreated();

        $this->assertSame(1, BirFormDraft::count());
    }

    public function test_an_employee_gets_the_role_message_not_the_switch_message(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);

        foreach (['/api/bir/config', '/api/bir/drafts'] as $path) {
            $this->actingAs($employee)->getJson($path)
                ->assertForbidden()
                ->assertExactJson(['success' => false, 'message' => 'Unauthorized. Admin access required.']);
        }

        $this->actingAs($employee)
            ->postJson('/api/bir/drafts', ['form_type' => '1601-C', 'period' => '2026-09'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Unauthorized. Admin access required.');

        $this->assertSame(0, BirFormDraft::count());
    }

    /**
     * A real 1601-C made through POST /bir/drafts by an accounting preparer, with the switch
     * briefly on, then moved straight to a status. The caller switches it off afterwards.
     */
    private function formIn(string $status): int
    {
        SystemSettings::set('bir_forms_enabled', true, null, 'boolean');

        $preparer = User::factory()->create(['role' => 'accounting']);
        $id = $this->actingAs($preparer)
            ->postJson('/api/bir/drafts', ['form_type' => '1601-C', 'period' => '2026-09'])
            ->assertCreated()
            ->json('data.id');

        BirFormDraft::whereKey($id)->update(['status' => $status]);

        return $id;
    }

    private function switchOff(string $off): void
    {
        if ($off === 'no row') {
            SystemSettings::where('key', 'bir_forms_enabled')->delete();
            $this->assertNull(SystemSettings::where('key', 'bir_forms_enabled')->first());

            return;
        }

        SystemSettings::set('bir_forms_enabled', false, null, 'boolean');
        $this->assertFalse(SystemSettings::get('bir_forms_enabled', true));
    }
}
