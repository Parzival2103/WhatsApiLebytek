<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$automation = $root.'/docs/automation';

test('automation kit ships profile template, active profile and kit readme', function () use ($automation): void {
    foreach ([
        $automation.'/REPO-PROFILE.example.md',
        $automation.'/REPO-PROFILE.md',
        $automation.'/profiles/WhatsApiLebytek.md',
        $automation.'/INSTALL-WhatsApiLebytek.md',
        $automation.'/KIT-README.md',
    ] as $path) {
        expect($path)->toBeReadableFile();
    }
});

test('automation has nine generic AUTOMATION prompts that require REPO-PROFILE', function () use ($automation): void {
    $expected = [
        'AUTOMATION-00-daily-audit.md',
        'AUTOMATION-01-daily-spec.md',
        'AUTOMATION-02-audit-tech-debt.md',
        'AUTOMATION-03-audit-ux.md',
        'AUTOMATION-04-plan-writer.md',
        'AUTOMATION-05-wha-notify.md',
        'AUTOMATION-06-plan-readiness-gate.md',
        'AUTOMATION-07-plan-executor.md',
        'AUTOMATION-08-plan-closure.md',
    ];

    foreach ($expected as $name) {
        $path = $automation.'/'.$name;
        expect($path)->toBeReadableFile();
        $src = (string) file_get_contents($path);
        expect($src)->toContain('## Prompt');
        expect($src)->toContain('REPO-PROFILE');
        expect($src)->not->toContain(
            'Eres el auditor técnico senior del paquete Composer `lebytek/framework`'
        );
    }
});

test('REPO-PROFILE declares composer test and api ownership', function () use ($automation): void {
    $src = (string) file_get_contents($automation.'/REPO-PROFILE.md');
    expect($src)->toContain('Parzival2103/WhatsApiLebytek');
    expect($src)->toContain('composer test');
    expect($src)->toContain('Lebytek_Portal');
    expect($src)->toContain('Lebytek_Framework');
});

test('automation README documents nine-stage chain', function () use ($automation): void {
    $src = (string) file_get_contents($automation.'/README.md');
    expect($src)->toContain('AUTOMATION-00');
    expect($src)->toContain('AUTOMATION-08');
    expect($src)->toContain('REPO-PROFILE.md');
    expect($src)->toContain('KIT-README.md');
    expect($src)->toContain('CONTEXT.md');
});
