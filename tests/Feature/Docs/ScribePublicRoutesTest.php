<?php

test('legacy scribe paths on api host redirect to docs.lebytek.com', function () {
    $docsSite = rtrim((string) config('services.docs.site_url'), '/');

    $this->get('/docs')
        ->assertRedirect("{$docsSite}");

    $this->get('/docs.openapi')
        ->assertRedirect("{$docsSite}/openapi/openapi.yaml");

    $this->get('/docs.postman')
        ->assertRedirect("{$docsSite}/openapi/postman.json");
});

test('scribe does not expose live documentation routes on the api host', function () {
    expect(config('scribe.laravel.add_routes'))->toBeFalse();
});
