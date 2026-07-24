<?php

use App\Models\Integration\Instancia;
use App\Services\GreenApi\GreenApiInstanceSettings;
use Illuminate\Support\Facades\Http;

test('dry-run lists eligible instances without calling Green', function () {
    Http::fake();

    Instancia::factory()->authorized()->create([
        'id_instance' => '1101000001',
        'api_token_instance' => 'token-ok',
    ]);

    Instancia::factory()->create([
        'status' => 'deleted',
        'id_instance' => '1101000002',
        'api_token_instance' => 'token-deleted',
    ]);

    Instancia::factory()->create([
        'status' => 'deleting',
        'id_instance' => '1101000003',
        'api_token_instance' => 'token-deleting',
    ]);

    Instancia::factory()->create([
        'status' => 'provisioning',
        'id_instance' => null,
        'api_token_instance' => null,
    ]);

    $this->artisan('green:apply-send-delay', ['--dry-run' => true])
        ->expectsOutputToContain('1 eligible')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

test('apply pushes delay setting to eligible instances', function () {
    Http::fake([
        '*/waInstance1102000001/setSettings/*' => Http::response(['saveSettings' => true], 200),
    ]);

    Instancia::factory()->authorized()->create([
        'id_instance' => '1102000001',
        'api_token_instance' => 'token-apply',
    ]);

    $this->artisan('green:apply-send-delay')
        ->assertExitCode(0);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/waInstance1102000001/setSettings/')) {
            return false;
        }

        $data = $request->data();

        return ($request['delaySendMessagesMilliseconds'] ?? null) === GreenApiInstanceSettings::DELAY_SEND_MESSAGES_MILLISECONDS
            && ! array_key_exists('webhookUrl', $data)
            && ! array_key_exists('webhookUrlToken', $data);
    });
});

test('apply continues on partial failure and exits non-zero', function () {
    Http::fake([
        '*/waInstance1103000001/setSettings/*' => Http::response(['error' => 'boom'], 500),
        '*/waInstance1103000002/setSettings/*' => Http::response(['saveSettings' => true], 200),
    ]);

    $failing = Instancia::factory()->authorized()->create([
        'id_instance' => '1103000001',
        'api_token_instance' => 'token-fail',
    ]);

    Instancia::factory()->authorized()->create([
        'id_instance' => '1103000002',
        'api_token_instance' => 'token-ok',
    ]);

    $this->artisan('green:apply-send-delay')
        ->expectsOutputToContain((string) $failing->id)
        ->assertExitCode(1);

    Http::assertSentCount(2);
});

test('apply includes failed status when credentials exist', function () {
    Http::fake([
        '*/waInstance1104000001/setSettings/*' => Http::response(['saveSettings' => true], 200),
    ]);

    Instancia::factory()->create([
        'status' => 'failed',
        'id_instance' => '1104000001',
        'api_token_instance' => 'token-failed-but-live',
    ]);

    $this->artisan('green:apply-send-delay')
        ->assertExitCode(0);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/waInstance1104000001/setSettings/'));
});

test('apply skips deleting status even with credentials', function () {
    Http::fake();

    Instancia::factory()->create([
        'status' => 'deleting',
        'id_instance' => '1105000001',
        'api_token_instance' => 'token-teardown',
    ]);

    $this->artisan('green:apply-send-delay')
        ->expectsOutputToContain('No eligible')
        ->assertExitCode(0);

    Http::assertNothingSent();
});
