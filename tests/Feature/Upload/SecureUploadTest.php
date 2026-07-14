<?php

use App\Models\Core\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    if (! extension_loaded('gd')) {
        $this->markTestSkipped('GD extension is required for secure upload tests.');
    }

    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('uploads');
    config(['filesystems.uploads_disk' => 'uploads']);

    $this->tenant = Tenant::factory()->create();
    $this->user = User::factory()->forTenant($this->tenant)->create();
    $this->user->assignRole(Role::findByName('admin', 'web'));
});

function fakePngUpload(string $name = 'logo.png'): UploadedFile
{
    return UploadedFile::fake()->image($name, 16, 16);
}

test('secure upload rejects svg files', function () {
    $svg = UploadedFile::fake()->create('icon.svg', 100, 'image/svg+xml');

    $this->actingAs($this->user)
        ->post(route('admin.archivos.store'), [
            'file' => $svg,
            'purpose' => 'logo',
        ])
        ->assertSessionHasErrors('file');
});

test('secure upload accepts png and stores reencoded file', function () {
    $png = fakePngUpload('logo.png');

    $response = $this->actingAs($this->user)
        ->post(route('admin.archivos.store'), [
            'file' => $png,
            'purpose' => 'logo',
        ]);

    $response->assertCreated()
        ->assertJsonStructure(['publicId', 'purpose', 'mimeType']);

    $archivo = \App\Models\Core\Archivo::withoutGlobalScopes()->first();

    expect($archivo)->not->toBeNull()
        ->and($archivo->mime_type)->toBe('image/png')
        ->and(Storage::disk('uploads')->exists($archivo->path))->toBeTrue();

    $stored = Storage::disk('uploads')->get($archivo->path);
    expect($stored)->not->toBeEmpty();
});

test('archivo is served via authenticated controller route', function () {
    $png = fakePngUpload('logo.png');

    $this->actingAs($this->user)
        ->post(route('admin.archivos.store'), [
            'file' => $png,
            'purpose' => 'favicon',
        ])
        ->assertCreated();

    $archivo = \App\Models\Core\Archivo::withoutGlobalScopes()->first();

    $this->actingAs($this->user)
        ->get(route('admin.archivos.show', $archivo->public_id))
        ->assertOk()
        ->assertHeader('content-type', 'image/png');
});
