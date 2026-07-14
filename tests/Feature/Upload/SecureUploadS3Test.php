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
    Storage::fake('s3');
    config(['filesystems.uploads_disk' => 's3']);

    $this->tenant = Tenant::factory()->create();
    $this->user = User::factory()->forTenant($this->tenant)->create();
    $this->user->assignRole(Role::findByName('admin', 'web'));
});

test('secure upload stores on configured s3 disk', function () {
    $file = UploadedFile::fake()->image('logo.png', 16, 16);

    $this->actingAs($this->user)
        ->post(route('admin.archivos.store'), [
            'file' => $file,
            'purpose' => 'logo',
        ])
        ->assertCreated();

    $archivo = \App\Models\Core\Archivo::withoutGlobalScopes()->first();

    expect($archivo->disk)->toBe('s3')
        ->and(Storage::disk('s3')->exists($archivo->path))->toBeTrue();
});
