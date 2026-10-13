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
 * PUT /bir/switch/password (contract §1, §6): an admin sets and changes the company password that
 * guards the BIR switch. Only a bcrypt hash is stored and no endpoint ever returns it; the general
 * settings save refuses both switch keys. The passwords here are made up.
 *
 * Doesn't use EnablesBirForms: the password route works whether BIR is on or off.
 */
class BirSwitchPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'bir_switch_password_hash';

    private const FIRST = 'test-company-pass';

    private const SECOND = 'another-test-pass';

    private const CONFIRM_QUESTION = 'Are you sure? This deletes the company password and turns the BIR Form Assistant off.';

    public function test_the_first_password_needs_no_current_password(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->putJson('/api/bir/switch/password', $this->body(self::FIRST))
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'data' => ['switch_password_set' => true],
                'message' => 'Company password saved',
            ]);

        $this->assertTrue(Hash::check(self::FIRST, $this->savedHash()));
        $this->assertNotSame(self::FIRST, $this->savedHash(), 'The plain password was stored.');

        $audit = AuditLog::where('event', 'BIR_SWITCH_PASSWORD')->sole();
        $this->assertSame("{$admin->name} set the company password for the BIR switch", $audit->description);
        $this->assertNull($audit->old_values);
        $this->assertNull($audit->new_values);
    }

    public function test_changing_it_needs_the_right_current_password(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->setPassword(self::FIRST);

        $this->actingAs($admin)
            ->putJson('/api/bir/switch/password', $this->body(self::SECOND, self::FIRST))
            ->assertOk()
            ->assertJsonPath('data.switch_password_set', true);

        $this->assertTrue(Hash::check(self::SECOND, $this->savedHash()));
        $this->assertFalse(Hash::check(self::FIRST, $this->savedHash()));

        $audit = AuditLog::where('event', 'BIR_SWITCH_PASSWORD')->sole();
        $this->assertSame("{$admin->name} changed the company password for the BIR switch", $audit->description);
        $this->assertNull($audit->old_values);
        $this->assertNull($audit->new_values);
        $this->assertStringNotContainsString(self::SECOND, json_encode($audit->toArray()));
    }

    /** @return array<string, array{?string}> */
    public static function wrongCurrentPasswords(): array
    {
        return ['wrong' => ['not-the-password'], 'missing' => [null]];
    }

    #[DataProvider('wrongCurrentPasswords')]
    public function test_a_wrong_or_missing_current_password_changes_nothing(?string $current): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->setPassword(self::FIRST);
        $hashBefore = $this->savedHash();

        $this->actingAs($admin)
            ->putJson('/api/bir/switch/password', $this->body(self::SECOND, $current))
            ->assertStatus(422)
            ->assertExactJson(['success' => false, 'message' => 'The current company password is incorrect']);

        $this->assertSame($hashBefore, $this->savedHash());
        $this->assertSame(0, AuditLog::where('event', 'BIR_SWITCH_PASSWORD')->count());

        // The old password still works: it can still change the password.
        $this->actingAs($admin)
            ->putJson('/api/bir/switch/password', $this->body(self::SECOND, self::FIRST))
            ->assertOk();
    }

    /** @return array<string, array{array<string, string>}> */
    public static function badNewPasswords(): array
    {
        return [
            'too short' => [['password' => 'short1', 'password_confirmation' => 'short1']],
            'not confirmed' => [['password' => self::FIRST, 'password_confirmation' => 'something-else']],
            'confirmation missing' => [['password' => self::FIRST]],
            'password missing' => [[]],
        ];
    }

    #[DataProvider('badNewPasswords')]
    public function test_a_bad_new_password_is_refused(array $body): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->putJson('/api/bir/switch/password', $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertNull(SystemSettings::where('key', self::KEY)->first());
    }

    /** @return array<string, array{string}> */
    public static function nonAdminRoles(): array
    {
        return ['hr' => ['hr'], 'accounting' => ['accounting'], 'employee' => ['employee']];
    }

    #[DataProvider('nonAdminRoles')]
    public function test_only_an_admin_can_set_it(string $role): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]))
            ->putJson('/api/bir/switch/password', $this->body(self::FIRST))
            ->assertForbidden();

        $this->assertNull(SystemSettings::where('key', self::KEY)->first());
    }

    /** @return array<string, array{string}> */
    public static function settingsReaders(): array
    {
        return ['admin' => ['admin'], 'hr' => ['hr'], 'accounting' => ['accounting']];
    }

    #[DataProvider('settingsReaders')]
    public function test_the_hash_is_never_returned(string $role): void
    {
        $this->setPassword(self::FIRST);
        SystemSettings::set('company_name', 'Test Company Inc.', null, 'string');
        $reader = User::factory()->create(['role' => $role]);

        $list = $this->actingAs($reader)->getJson('/api/admin/settings')->assertOk();
        $this->assertStringNotContainsString('$2y$', $list->getContent());
        $this->assertNotContains(self::KEY, array_column($list->json('data'), 'key'));
        $this->assertContains('company_name', array_column($list->json('data'), 'key'));

        foreach ([self::KEY, strtoupper(self::KEY)] as $key) {
            $one = $this->actingAs($reader)->getJson("/api/admin/settings/{$key}")
                ->assertNotFound()
                ->assertExactJson(['success' => false, 'message' => 'Setting not found']);
            $this->assertStringNotContainsString('$2y$', $one->getContent());
        }

        $config = $this->actingAs($reader)->getJson('/api/bir/config')->assertOk();
        $this->assertStringNotContainsString('$2y$', $config->getContent());
    }

    public function test_the_general_settings_save_refuses_both_switch_keys(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->setPassword(self::FIRST);
        SystemSettings::set('bir_forms_enabled', false, 'Master switch', 'boolean');
        $hashBefore = $this->savedHash();

        $attempts = [
            [self::KEY, 'not-a-hash', 'string'],
            [strtoupper(self::KEY), 'not-a-hash', 'string'],
            ['bir_forms_enabled', true, 'boolean'],
            ['BIR_FORMS_ENABLED', true, 'boolean'],
        ];
        foreach ($attempts as [$key, $value, $type]) {
            $this->actingAs($admin)
                ->putJson("/api/admin/settings/{$key}", ['value' => $value, 'type' => $type])
                ->assertForbidden()
                ->assertExactJson(['success' => false, 'message' => 'Use the BIR switch, which asks for the company password']);
        }

        $this->assertSame($hashBefore, $this->savedHash());
        $this->assertFalse(SystemSettings::get('bir_forms_enabled'));
        $this->assertSame(1, SystemSettings::where('key', 'bir_forms_enabled')->count());

        // Any other key still saves.
        $this->actingAs($admin)
            ->putJson('/api/admin/settings/company_name', ['value' => 'Test Company Inc.', 'type' => 'string'])
            ->assertOk();
        $this->assertSame('Test Company Inc.', SystemSettings::get('company_name'));
    }

    public function test_config_says_whether_a_password_is_set(): void
    {
        $accounting = User::factory()->create(['role' => 'accounting']);

        $this->actingAs($accounting)->getJson('/api/bir/config')
            ->assertOk()
            ->assertJsonPath('data.switch_password_set', false);

        $this->setPassword(self::FIRST);

        $this->actingAs($accounting)->getJson('/api/bir/config')
            ->assertOk()
            ->assertJsonPath('data.switch_password_set', true);
    }

    public function test_the_clear_command_removes_the_password_and_turns_bir_off(): void
    {
        $this->setPassword(self::FIRST);
        SystemSettings::set('bir_forms_enabled', true, 'Master switch', 'boolean');

        $this->artisan('bir:clear-switch-password')
            ->expectsConfirmation(self::CONFIRM_QUESTION, 'yes')
            ->assertSuccessful();

        $this->assertNull(SystemSettings::where('key', self::KEY)->first());
        $this->assertFalse(SystemSettings::get('bir_forms_enabled', true));
        $this->assertSame('Master switch', SystemSettings::where('key', 'bir_forms_enabled')->value('description'));

        // An admin can then set a new one without the old password.
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->putJson('/api/bir/switch/password', $this->body(self::SECOND))
            ->assertOk();
    }

    public function test_the_clear_command_changes_nothing_when_not_confirmed(): void
    {
        $this->setPassword(self::FIRST);
        SystemSettings::set('bir_forms_enabled', true, 'Master switch', 'boolean');
        $hashBefore = $this->savedHash();

        $this->artisan('bir:clear-switch-password')
            ->expectsConfirmation(self::CONFIRM_QUESTION, 'no')
            ->assertSuccessful();

        $this->assertSame($hashBefore, $this->savedHash());
        $this->assertTrue(SystemSettings::get('bir_forms_enabled'));
    }

    public function test_a_sixth_try_in_a_minute_is_refused(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->setPassword(self::FIRST);

        for ($i = 1; $i <= 5; $i++) {
            $this->actingAs($admin)
                ->putJson('/api/bir/switch/password', $this->body(self::SECOND, "wrong-guess-{$i}"))
                ->assertStatus(422);
        }

        // Even the right password is refused until the minute is up.
        $this->actingAs($admin)
            ->putJson('/api/bir/switch/password', $this->body(self::SECOND, self::FIRST))
            ->assertStatus(429);

        $this->assertTrue(Hash::check(self::FIRST, $this->savedHash()));
    }

    /** @return array<string, string> */
    private function body(string $password, ?string $current = null): array
    {
        $body = ['password' => $password, 'password_confirmation' => $password];
        if ($current !== null) {
            $body['current_password'] = $current;
        }

        return $body;
    }

    private function setPassword(string $password): void
    {
        SystemSettings::set(self::KEY, Hash::make($password), null, 'string');
    }

    private function savedHash(): ?string
    {
        return SystemSettings::where('key', self::KEY)->value('value');
    }
}
