<?php

namespace Tests\Feature\Bir;

use App\Models\User;
use App\Models\UserSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A user's saved signature (contract: docs/bir-api-contract.md): uploaded once, replaced or
 * removed anytime, kept on the private disk, and never described by its path in any JSON.
 * Every image is a generated blank one; no real signature is ever used.
 */
class BirSignatureUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_any_role_can_upload_a_signature(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);

        $this->actingAs($employee)
            ->post('/api/me/signature', ['signature' => $this->image()], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJson(['success' => true, 'data' => ['has_signature' => true]]);

        $signature = UserSignature::where('user_id', $employee->id)->firstOrFail();
        $this->assertStringStartsWith("signatures/{$employee->id}/", $signature->path);
        $this->assertStringEndsWith('.png', $signature->path);
        Storage::disk('local')->assertExists($signature->path);

        $this->actingAs($employee)->getJson('/api/me/signature')
            ->assertOk()
            ->assertJson(['data' => ['has_signature' => true]]);
    }

    public function test_replacing_a_signature_deletes_the_old_file(): void
    {
        $user = User::factory()->create(['role' => 'accounting']);

        $this->actingAs($user)->post('/api/me/signature', ['signature' => $this->image()], ['Accept' => 'application/json'])->assertCreated();
        $oldPath = UserSignature::where('user_id', $user->id)->value('path');

        $this->actingAs($user)
            ->post('/api/me/signature', ['signature' => UploadedFile::fake()->image('sig.jpg', 400, 150)], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertSame(1, UserSignature::where('user_id', $user->id)->count());
        $newPath = UserSignature::where('user_id', $user->id)->value('path');
        $this->assertNotSame($oldPath, $newPath);
        $this->assertStringEndsWith('.jpg', $newPath);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($newPath);
    }

    public function test_removing_a_signature_deletes_the_file_and_the_row(): void
    {
        $user = User::factory()->create(['role' => 'hr']);
        $this->actingAs($user)->post('/api/me/signature', ['signature' => $this->image()], ['Accept' => 'application/json'])->assertCreated();
        $path = UserSignature::where('user_id', $user->id)->value('path');

        $this->actingAs($user)->deleteJson('/api/me/signature')
            ->assertOk()
            ->assertJson(['data' => ['has_signature' => false]]);

        $this->assertSame(0, UserSignature::where('user_id', $user->id)->count());
        Storage::disk('local')->assertMissing($path);
        $this->actingAs($user)->getJson('/api/me/signature')->assertJson(['data' => ['has_signature' => false, 'uploaded_at' => null]]);
    }

    public function test_a_file_that_is_not_png_or_jpg_is_refused(): void
    {
        $user = User::factory()->create(['role' => 'employee']);

        $this->actingAs($user)
            ->post('/api/me/signature', ['signature' => UploadedFile::fake()->create('sig.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('signature');

        $this->assertSame(0, UserSignature::count());
    }

    public function test_a_file_over_one_megabyte_is_refused(): void
    {
        $user = User::factory()->create(['role' => 'employee']);

        $this->actingAs($user)
            ->post('/api/me/signature', ['signature' => $this->image()->size(1025)], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('signature');

        $this->assertSame(0, UserSignature::count());
    }

    public function test_an_image_larger_than_2000_by_1000_is_refused(): void
    {
        $user = User::factory()->create(['role' => 'employee']);

        foreach ([[2001, 500], [800, 1001]] as [$width, $height]) {
            $this->actingAs($user)
                ->post('/api/me/signature', ['signature' => UploadedFile::fake()->image('sig.png', $width, $height)], ['Accept' => 'application/json'])
                ->assertStatus(422)
                ->assertJsonValidationErrors('signature');
        }

        $this->assertSame(0, UserSignature::count());
    }

    public function test_every_route_needs_a_login(): void
    {
        $this->getJson('/api/me/signature')->assertUnauthorized();
        $this->getJson('/api/me/signature/image')->assertUnauthorized();
        $this->post('/api/me/signature', ['signature' => $this->image()], ['Accept' => 'application/json'])->assertUnauthorized();
        $this->deleteJson('/api/me/signature')->assertUnauthorized();
    }

    public function test_the_json_never_contains_the_file_path(): void
    {
        $user = User::factory()->create(['role' => 'accounting']);

        $upload = $this->actingAs($user)->post('/api/me/signature', ['signature' => $this->image()], ['Accept' => 'application/json']);
        $path = UserSignature::where('user_id', $user->id)->value('path');
        $show = $this->actingAs($user)->getJson('/api/me/signature');

        foreach ([$upload, $show] as $response) {
            $this->assertStringNotContainsString($path, $response->getContent());
            $this->assertStringNotContainsString('signatures/', $response->getContent());
        }
        $this->assertArrayNotHasKey('path', UserSignature::first()->toArray());
    }

    public function test_the_owner_can_fetch_their_own_image_and_it_is_not_cached(): void
    {
        $user = User::factory()->create(['role' => 'employee']);
        $this->actingAs($user)->post('/api/me/signature', ['signature' => $this->image()], ['Accept' => 'application/json'])->assertCreated();
        $path = UserSignature::where('user_id', $user->id)->value('path');

        $response = $this->actingAs($user)->get('/api/me/signature/image')->assertOk();

        $response->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(Storage::disk('local')->get($path), $response->getContent());
    }

    public function test_the_image_route_is_404_without_a_signature(): void
    {
        $user = User::factory()->create(['role' => 'employee']);

        // Someone else's signature is never served: the route only ever looks at the caller.
        $other = User::factory()->create(['role' => 'accounting']);
        $this->actingAs($other)->post('/api/me/signature', ['signature' => $this->image()], ['Accept' => 'application/json'])->assertCreated();

        $this->actingAs($user)->getJson('/api/me/signature/image')
            ->assertNotFound()
            ->assertJson(['success' => false]);
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->image('sig.png', 600, 200);
    }
}
