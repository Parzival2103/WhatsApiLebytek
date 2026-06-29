<?php

use App\Models\Core\Tenant;
use App\Models\Log\AuditLog;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\ConfigurationService;
use App\Support\Config\ConfigurationKey;
use App\Support\TenantContext;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    TenantContext::set($this->tenant->id);
    $this->user = User::factory()->forTenant($this->tenant)->create();
    $this->config = app(ConfigurationService::class);
    $this->audit = app(AuditLogService::class);
});

test('configuration change creates audit log entry', function () {
    $this->config->set(ConfigurationKey::LayoutMode, 'top', $this->user);

    $log = AuditLog::withoutGlobalScopes()->first();

    expect($log)->not->toBeNull()
        ->and($log->action)->toBe('config.updated')
        ->and($log->user_id)->toBe($this->user->id)
        ->and($log->tenant_id)->toBe($this->tenant->id);
});

test('audit log sanitizes sensitive fields', function () {
    $this->audit->record(
        action: 'test.sensitive',
        actor: $this->user,
        before: ['email' => 'user@example.com', 'mode' => 'top'],
        after: ['password' => 'secret', 'mode' => 'side'],
    );

    $log = AuditLog::withoutGlobalScopes()->first();

    expect($log->before['email'])->toBe('[redacted]')
        ->and($log->before['mode'])->toBe('top')
        ->and($log->after['password'])->toBe('[redacted]')
        ->and($log->after['mode'])->toBe('side');
});

test('audit log model cannot be updated or deleted', function () {
    $log = AuditLog::withoutGlobalScopes()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
        'action' => 'test.immutable',
        'created_at' => now(),
    ]);

    expect($log->update(['action' => 'changed']))->toBeFalse()
        ->and($log->delete())->toBeFalse();
});
