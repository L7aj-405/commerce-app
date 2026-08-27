<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Brand appearance token architecture — --primary must be a genuinely
| independent, store-settable token (not an alias of the old fixed --accent),
| and every token name the appearance brief lists must exist in both the
| light and dark reach of app.css.
|--------------------------------------------------------------------------
*/

function ttAppCss(): string
{
    return file_get_contents(resource_path('css/app.css'));
}

it('defines every token named in the appearance brief', function (): void {
    $css = ttAppCss();

    foreach ([
        '--app-bg', '--app-canvas', '--surface', '--surface-soft', '--card', '--card-muted',
        '--text', '--text-muted', '--border', '--primary', '--primary-soft', '--primary-contrast',
        '--accent', '--success', '--warning', '--danger', '--shadow',
        '--radius-card', '--radius-button', '--font-sans', '--density-scale',
    ] as $token) {
        expect($css)->toContain($token, "Expected app.css to define the {$token} token.");
    }
});

it('decouples --primary from the old --accent alias', function (): void {
    $css = ttAppCss();

    expect($css)->not->toContain('--color-primary:        var(--accent)')
        ->not->toContain('--color-primary-strong: var(--accent-strong)');

    expect($css)->toContain('--color-primary:          var(--primary)')
        ->toContain('--color-accent:           var(--accent)');
});

it('derives --primary-strong/--primary-soft instead of storing separate hex values', function (): void {
    $css = ttAppCss();

    expect($css)->toContain('color-mix(in srgb, var(--primary)');
});

it('scopes border-radius customization to a data-radius attribute, defaulting to today\'s look', function (): void {
    $css = ttAppCss();

    expect($css)->toContain(':root[data-radius="soft"]')
        ->toContain(':root[data-radius="pill"]')
        ->toContain('--radius-card:   1rem')
        ->toContain('--radius-button: 0.625rem');
});

it('scopes density to a data-density attribute', function (): void {
    $css = ttAppCss();

    expect($css)->toContain(':root[data-density="compact"]');
});
