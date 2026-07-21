<?php

use App\Jobs\ProvisionGreenInstanceJob;
use App\Models\Integration\Instancia;
use App\Services\GreenApi\PartnerClient;
use Illuminate\Support\Facades\Http;

test('provision job resumes configuring when credentials already exist', function () {
    Http::fake([
        '*/waInstance770022689574/setSettings/*' => Http::response(['saveSettings' => true], 200),
        '*/waInstance770022689574/getStateInstance/*' => Http::response(['stateInstance' => 'notAuthorized'], 200),
        '*/partner/createInstance/*' => Http::response(['should' => 'not-be-called'], 500),
    ]);

    $instancia = Instancia::factory()->create([
        'status' => 'configuring',
        'id_instance' => '770022689574',
        'api_token_instance' => 'secret-token',
        'last_error' => 'previous setSettings failure',
    ]);

    $job = new ProvisionGreenInstanceJob($instancia->id);
    $job->handle(app(PartnerClient::class));

    $instancia->refresh();

    expect($instancia->status)->toBe('waiting_qr')
        ->and($instancia->green_state)->toBe('notAuthorized')
        ->and($instancia->last_error)->toBeNull();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/partner/createInstance/'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/setSettings/'));
});
