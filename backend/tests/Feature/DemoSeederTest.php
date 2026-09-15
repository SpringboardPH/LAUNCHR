<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\SystemSettings;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_seed_does_not_create_the_springboard_roster(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('users', ['email' => 'dev@springboardph.com']);
        $this->assertDatabaseMissing('employees', ['email' => 'juan@springboardph.com']);
        $this->assertDatabaseMissing('users', ['email' => 'hr@springboardph.com']);
    }

    public function test_demo_seed_loads_roster_and_turns_off_otp_and_geo_capture(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->assertDatabaseHas('employees', ['email' => 'juan@springboardph.com']);
        $this->assertDatabaseHas('users', ['email' => 'hr@springboardph.com', 'role' => 'hr']);
        $this->assertFalse((bool) SystemSettings::get('login_otp_required'));
        $this->assertFalse((bool) SystemSettings::get('geo_capture_enabled'));
        $this->assertFalse((bool) SystemSettings::get('geofence_enabled'));
        $this->assertNotNull(
            AttendanceLog::query()->whereNotNull('clock_in_lat')->first()
        );

        $response = $this->postJson('/api/auth/request-otp', [
            'email' => 'juan@springboardph.com',
            'password' => 'password',
        ]);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertNull($response->json('data.user_id'));
        $this->assertIsString($response->json('data.token'));
        $this->assertNotSame('', $response->json('data.token'));
    }

    public function test_demo_seed_gives_every_employee_a_login(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->assertSame(0, Employee::query()->whereNull('user_id')->count());

        $kimLogin = $this->postJson('/api/auth/request-otp', [
            'email' => 'kim.fernandez@springboardph.com',
            'password' => 'password',
        ]);

        $kimLogin->assertOk()->assertJsonPath('success', true);
        $this->assertIsString($kimLogin->json('data.token'));
        $this->assertNotSame('', $kimLogin->json('data.token'));

        $admin = User::query()->where('email', 'dev@springboardph.com')->first();
        $users = $this->actingAs($admin)->getJson('/api/admin/users');
        $users->assertOk();
        $this->assertSame(29, $users->json('pagination.total'));
    }
}
