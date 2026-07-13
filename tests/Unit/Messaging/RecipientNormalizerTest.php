<?php

use App\Services\Messaging\RecipientNormalizer;
use InvalidArgumentException;

test('normalizes e164 digits and strips non-digits', function () {
    $n = new RecipientNormalizer;

    expect($n->normalize('5215512345678'))->toBe('5215512345678')
        ->and($n->normalize('+52 155 1234 5678'))->toBe('5215512345678');
});

test('passes through whatsapp group chat id', function () {
    $n = new RecipientNormalizer;
    $group = '120363012345678901@g.us';

    expect($n->normalize($group))->toBe($group)
        ->and($n->normalize('  '.$group.'  '))->toBe($group);
});

test('rejects invalid recipients', function (string $raw) {
    $n = new RecipientNormalizer;
    expect(fn () => $n->normalize($raw))->toThrow(InvalidArgumentException::class);
})->with([
    '',
    'foo',
    '123@g.us',
    '120363012345678901@c.us',
    '120363012345678901@G.US',
    '@g.us',
    '52',
]);
