<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Shared component/utility layer modernization: the primary action color
| (buttons, focus rings, active chips) must come from the --primary token,
| not a hardcoded indigo/blue Tailwind hue, or a brand color change (Settings
| -> Appearance) would not actually cascade through these shared pieces.
|--------------------------------------------------------------------------
*/

function ctcRead(string $relative): string
{
    return file_get_contents(resource_path($relative));
}

it('Button.jsx primary variant tracks the brand token, not a hardcoded hue', function (): void {
    $source = ctcRead('js/Components/Button.jsx');

    expect($source)->toContain('bg-primary')
        ->not->toContain('bg-indigo-600');
});

it('app.css .btn-primary tracks the brand token, not a hardcoded hue', function (): void {
    $source = ctcRead('css/app.css');

    expect($source)->toContain('@utility btn-primary')
        ->not->toContain('bg-blue-600');
});

it('shared form/data components reference the radius tokens', function (): void {
    foreach ([
        'js/Components/Button.jsx',
        'js/Components/Card.jsx',
        'js/Components/DataTable.jsx',
        'js/Components/SearchFilterBar.jsx',
    ] as $relative) {
        $source = ctcRead($relative);
        expect($source)->toContain('var(--radius-', "Expected {$relative} to reference a --radius-* token.");
    }
});

it('StatusBadge keeps success/warning/danger semantic but routes them through tokens', function (): void {
    $source = ctcRead('js/Components/StatusBadge.jsx');

    expect($source)->toContain('bg-success-soft text-success')
        ->toContain('bg-warning-soft text-warning')
        ->toContain('bg-danger-soft text-danger');
});

it('SearchFilterBar active-filter chips and focus states track the brand token', function (): void {
    $source = ctcRead('js/Components/SearchFilterBar.jsx');

    expect($source)->toContain('bg-primary-soft text-primary')
        ->not->toContain('bg-indigo-500/15 text-indigo-700');
});
