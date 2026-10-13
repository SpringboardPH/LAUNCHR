<?php

namespace Tests\Feature\Bir;

use App\Models\AuditLog;
use App\Models\SystemSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PUT /bir/switch (contract §1, §6): an admin turns bir_forms_enabled on or off with the company
 * password. It works while BIR is off, or BIR could never be turned on. The password is made up.
 *
 * Doesn't use EnablesBirForms: the switch starts off here, as in a fresh install.
 */
class BirSwitchTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'test-company-pass';

    private const DESCRIPTION = 'Master switch for the BIR Form Assistant';

    public function test_an_admin_with_the_right_password_turns_it_on_then_off(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->setPassword();
        SystemSettings::set('bir_forms_enabled', false, self::DESCRIPTION, 'boolean');

        $this->actingAs($admin)->putJson('/api/bir/switch', ['enabled' => true, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'data' => ['enabled' => true],
                'message' => 'The BIR Form Assistant is now on',
            ]);

        $this->assertTrue(SystemSettings::get('bir_forms_enabled'));
        $this->assertSame(self::DESCRIPTION, SystemSettings::where('key', 'bir_forms_enabled')->value('description'));
        $this->actingAs($admin)->getJson('/api/bir/config')->assertOk()->assertJsonPath('data.enabled', true);
        $this->actingAs($admin)->getJson('/api/bir/drafts')->assertOk();

        $this->actingAs($admin)->putJson('/api/bir/switch', ['enabled' => false, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'data' => ['enabled' => false],
                'message' => 'The BIR Form Assistant is now off',
            ]);

        $this->assertFalse(SystemSettings::get('bir_forms_enabled'));
        $this->actingAs($admin)->getJson('/api/bir/config')->assertOk()->assertJsonPath('data.enabled', false);
        $this->actingAs($admin)->getJson('/api/bir/drafts')->assertForbidden();

        $audits = AuditLog::where('event', 'BIR_SWITCH')->orderBy('id')->get();
        $this->assertCount(2, $audits);
        $this->assertSame("{$admin->name} turned the BIR Form Assistant on", $audits[0]->description);
        $this->assertSame(['enabled' => false], $audits[0]->old_values);
        $this->assertSame(['enabled' => true], $audits[0]->new_values);
        $this->assertSame("{$admin->name} turned the BIR Form Assistant off", $audits[1]->description);
        $this->assertSame(['enabled' => true], $audits[1]->old_values);
        $this->assertSame(['enabled' => false], $audits[1]->new_values);
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($audits->toArray()));
    }

    public function test_it_works_with_no_switch_row_at_all(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->setPassword();
        $this->assertNull(SystemSettings::where('key', 'bir_forms_enabled')->first());

        $this->actingAs($admin)->putJson('/api/bir/switch', ['enabled' => true, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.enabled', true);

        $this->assertTrue(SystemSettings::get('bir_forms_enabled'));
        $this->assertSame(['enabled' => false], AuditLog::where('event', 'BIR_SWITCH')->sole()->old_values);
    }

    public function test_a_wrong_password_changes_nothing(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->setPassword();
        SystemSettings::set('bir_forms_enabled', false, self::DESCRIPTION, 'boolean');

        $this->actingAs($admin)->putJson('/api/bir/switch', ['enabled' => true, 'password' => 'not-the-password'])
            ->assertStatus(422)
            ->assertExactJson(['success' => false, 'message' => 'The company password is incorrect']);

        $this->assertFalse(SystemSettings::get('bir_forms_enabled'));
        $this->assertSame(0, AuditLog::where('event', 'BIR_SWITCH')->count());
    }

    public function test_with_no_password_set_up_it_refuses(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->putJson('/api/bir/switch', ['enabled' => true, 'password' => self::PASSWORD])
            ->assertStatus(422)
            ->assertExactJson(['success' => false, 'message' => 'No company password is set up']);

        $this->assertNull(SystemSettings::where('key', 'bir_forms_enabled')->first());
        $this->assertSame(0, AuditLog::where('event', 'BIR_SWITCH')->count());
    }

    /** @return array<string, array{string}> */
    public static function nonAdminRoles(): array
    {
        return ['hr' => ['hr'], 'accounting' => ['accounting'], 'employee' => ['employee']];
    }

    #[DataProvider('nonAdminRoles')]
    public function test_only_an_admin_can_use_it(string $role): void
    {
        $this->setPassword();

        $this->actingAs(User::factory()->create(['role' => $role]))
            ->putJson('/api/bir/switch', ['enabled' => true, 'password' => self::PASSWORD])
            ->assertForbidden();

        $this->assertNull(SystemSettings::where('key', 'bir_forms_enabled')->first());
        $this->assertSame(0, AuditLog::where('event', 'BIR_SWITCH')->count());
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function badBodies(): array
    {
        return [
            'enabled missing' => [['password' => self::PASSWORD], 'enabled'],
            'password missing' => [['enabled' => true], 'password'],
            'enabled not a boolean' => [['enabled' => 'yes please', 'password' => self::PASSWORD], 'enabled'],
            'empty body' => [[], 'enabled'],
        ];
    }

    #[DataProvider('badBodies')]
    public function test_a_bad_body_is_refused(array $body, string $field): void
    {
        $this->setPassword();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->putJson('/api/bir/switch', $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);

        $this->assertNull(SystemSettings::where('key', 'bir_forms_enabled')->first());
    }

    public function test_a_sixth_try_in_a_minute_is_refused(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->setPassword();

        for ($i = 1; $i <= 5; $i++) {
            $this->actingAs($admin)
                ->putJson('/api/bir/switch', ['enabled' => true, 'password' => "wrong-guess-{$i}"])
                ->assertStatus(422);
        }

        // Even the right password is refused until the minute is up.
        $this->actingAs($admin)
            ->putJson('/api/bir/switch', ['enabled' => true, 'password' => self::PASSWORD])
            ->assertStatus(429);

        $this->assertNull(SystemSettings::where('key', 'bir_forms_enabled')->first());
    }

    private function setPassword(): void
    {
        SystemSettings::set('bir_switch_password_hash', Hash::make(self::PASSWORD), null, 'string');
    }
}
