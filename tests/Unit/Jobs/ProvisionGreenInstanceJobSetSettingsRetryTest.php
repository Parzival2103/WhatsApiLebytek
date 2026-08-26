<?php

use App\Jobs\ProvisionGreenInstanceJob;
use App\Models\Integration\Instancia;
use App\Services\GreenApi\PartnerClient;
use Illuminate\Support\Facades\Http;

test('provision job retries setSettings after fresh Green credentials return 401', function () {
    config([
        'services.green_api.partner_token' => 'test-partner-token',
        'services.green_api.provision_set_settings_initial_delay' => 0,
        'services.green_api.provision_set_settings_retry_delay' => 0,
        'services.green_api.provision_get_state_retry_delay' => 0,
    ]);

    Http::fake([
        '*/partner/createInstance/*' => Http::response([
            'idInstance' => '770022698840',
            'apiTokenInstance' => 'fresh-token',
        ], 200),
        '*/waInstance770022698840/setSettings/*' => Http::sequence()
            ->push('', 401)
            ->push(['saveSettings' => true], 200),
        '*/waInstance770022698840/getStateInstance/*' => Http::response(['stateInstance' => 'notAuthorized'], 200),
    ]);

    $instancia = Instancia::factory()->create([
        'status' => 'provisioning',
        'id_instance' => null,
        'api_token_instance' => null,
    ]);

    $job = new ProvisionGreenInstanceJob($instancia->id);
    $job->handle(app(PartnerClient::class));

    $instancia->refresh();

    expect($instancia->status)->toBe('waiting_qr')
        ->and($instancia->green_state)->toBe('notAuthorized')
        ->and($instancia->last_error)->toBeNull();

    Http::assertSentCount(4);
});

test('provision job degrades to waiting_qr when setSettings keeps failing but Green instance is alive', function () {
    config([
        'services.green_api.partner_token' => 'test-partner-token',
        'services.green_api.provision_set_settings_initial_delay' => 0,
        'services.green_api.provision_set_settings_retry_delay' => 0,
        'services.green_api.provision_get_state_retry_delay' => 0,
    ]);

    Http::fake([
        '*/waInstance770022698856/setSettings/*' => Http::response('', 401),
        '*/waInstance770022698856/getStateInstance/*' => Http::response(['stateInstance' => 'notAuthorized'], 200),
    ]);

    $instancia = Instancia::factory()->create([
        'status' => 'configuring',
        'id_instance' => '770022698856',
        'api_token_instance' => 'fresh-token',
    ]);

    $job = new ProvisionGreenInstanceJob($instancia->id);
    $job->job = Mockery::mock();
    $job->job->shouldReceive('attempts')->andReturn(3);
    $job->handle(app(PartnerClient::class));

    $instancia->refresh();

    expect($instancia->status)->toBe('waiting_qr')
        ->and($instancia->green_state)->toBe('notAuthorized')
        ->and($instancia->last_error)->toBeNull();
});

test('provision job retries setSettings 401 even when Green credentials already exist (job resume)', function () {
    config([
        'services.green_api.partner_token' => 'test-partner-token',
        'services.green_api.provision_set_settings_initial_delay' => 0,
        'services.green_api.provision_set_settings_retry_delay' => 0,
        'services.green_api.provision_get_state_retry_delay' => 0,
    ]);

    Http::fake([
        '*/waInstance770022698857/setSettings/*' => Http::sequence()
            ->push('', 401)
            ->push('', 401)
            ->push(['saveSettings' => true], 200),
        '*/waInstance770022698857/getStateInstance/*' => Http::response(['stateInstance' => 'notAuthorized'], 200),
        '*/partner/createInstance/*' => Http::response(['should' => 'not-be-called'], 500),
    ]);

    $instancia = Instancia::factory()->create([
        'status' => 'configuring',
        'id_instance' => '770022698857',
        'api_token_instance' => 'existing-token',
    ]);

    $job = new ProvisionGreenInstanceJob($instancia->id);
    $job->handle(app(PartnerClient::class));

    $instancia->refresh();

    expect($instancia->status)->toBe('waiting_qr')
        ->and($instancia->green_state)->toBe('notAuthorized')
        ->and($instancia->last_error)->toBeNull();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/partner/createInstance/'));
});

test('provision job waits through transient getState 401 before marking failed', function () {
    config([
        'services.green_api.partner_token' => 'test-partner-token',
        'services.green_api.provision_set_settings_initial_delay' => 0,
        'services.green_api.provision_set_settings_retry_delay' => 0,
        'services.green_api.provision_get_state_retry_delay' => 0,
        'services.green_api.provision_set_settings_max_attempts' => 2,
        'services.green_api.provision_get_state_max_attempts' => 3,
    ]);

    Http::fake([
        '*/waInstance770022698858/setSettings/*' => Http::response('', 401),
        '*/waInstance770022698858/getStateInstance/*' => Http::sequence()
            ->push('', 401)
            ->push('', 401)
            ->push(['stateInstance' => 'notAuthorized'], 200),
    ]);

    $instancia = Instancia::factory()->create([
        'status' => 'configuring',
        'id_instance' => '770022698858',
        'api_token_instance' => 'existing-token',
    ]);

    $job = new ProvisionGreenInstanceJob($instancia->id);
    $job->job = Mockery::mock();
    $job->job->shouldReceive('attempts')->andReturn(3);
    $job->handle(app(PartnerClient::class));

    $instancia->refresh();

    expect($instancia->status)->toBe('waiting_qr')
        ->and($instancia->green_state)->toBe('notAuthorized')
        ->and($instancia->last_error)->toBeNull();
});

test('provision job marks failed with last_error when Green stays unauthorized by auth', function () {
    config([
        'services.green_api.partner_token' => 'test-partner-token',
        'services.green_api.provision_set_settings_initial_delay' => 0,
        'services.green_api.provision_set_settings_retry_delay' => 0,
        'services.green_api.provision_get_state_retry_delay' => 0,
        'services.green_api.provision_set_settings_max_attempts' => 2,
        'services.green_api.provision_get_state_max_attempts' => 2,
    ]);

    Http::fake([
        '*/waInstance770022698859/setSettings/*' => Http::response('', 401),
        '*/waInstance770022698859/getStateInstance/*' => Http::response('', 401),
    ]);

    $instancia = Instancia::factory()->create([
        'status' => 'configuring',
        'id_instance' => '770022698859',
        'api_token_instance' => 'bad-token',
    ]);

    $job = new ProvisionGreenInstanceJob($instancia->id);
    $job->job = Mockery::mock();
    $job->job->shouldReceive('attempts')->andReturn(3);
    $job->handle(app(PartnerClient::class));

    $instancia->refresh();

    expect($instancia->status)->toBe('failed')
        ->and($instancia->last_error)->toContain('setSettings failed (HTTP 401)');
});
