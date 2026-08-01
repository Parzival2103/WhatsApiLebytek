<?php

use App\Jobs\ProvisionGreenInstanceJob;
use App\Models\Integration\Instancia;
use App\Services\GreenApi\PartnerClient;
use Illuminate\Support\Facades\Http;

test('provision job retries setSettings after fresh Green credentials return 401', function () {
    config(['services.green_api.partner_token' => 'test-partner-token']);

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
    config(['services.green_api.partner_token' => 'test-partner-token']);

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
