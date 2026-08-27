<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Dark mode restoration. The premium shell previously force-redeclared
| --surface/--surface-2/--content/etc. to fixed light hex values on a
| `.quixotic-shell` class, which always beat :root.dark's values (CSS custom
| properties resolve at the element they're set on). This regression-guards
| that fix plus the shell's move off raw hardcoded hex onto theme tokens.
|--------------------------------------------------------------------------
*/

function themeAppCss(): string
{
    return file_get_contents(resource_path('css/app.css'));
}

function themeShellSource(): string
{
    return file_get_contents(resource_path('js/Components/PremiumDashboard/PremiumAppShell.jsx'));
}

function themeTopbarSource(): string
{
    return file_get_contents(resource_path('js/Components/PremiumDashboard/FloatingTopbar.jsx'));
}

it('no longer force-redeclares theme tokens on a non-root selector', function (): void {
    $css = themeAppCss();

    expect($css)->not->toContain('.quixotic-shell');
});

it('defines the full premium token set for both light and dark', function (): void {
    $css = themeAppCss();

    expect($css)->toContain(':root {')->toContain(':root.dark {');

    foreach (['--bg', '--canvas', '--surface-soft', '--card', '--card-muted', '--primary-soft', '--success', '--warning', '--danger', '--shadow', '--glass', '--table-row', '--table-hover'] as $token) {
        expect($css)->toContain($token, "Expected app.css to define the {$token} token.");
    }
});

it('maps the new tokens to Tailwind utilities via @theme inline', function (): void {
    $css = themeAppCss();

    foreach (['--color-bg', '--color-canvas', '--color-surface-soft', '--color-card', '--color-text', '--color-border', '--color-primary', '--color-success', '--color-warning', '--color-danger', '--color-glass', '--color-input', '--color-table-row', '--color-table-hover'] as $utility) {
        expect($css)->toContain($utility, "Expected app.css @theme inline to map {$utility}.");
    }
});

it('keeps the premium shell frame free of hardcoded hex colors', function (): void {
    $source = themeShellSource();

    expect($source)->not->toContain('quixotic-shell')
        ->not->toContain('#e5e6e2')
        ->not->toContain('#f4f4f1')
        ->not->toContain('#242824');

    expect($source)->toContain('bg-bg')->toContain('bg-canvas')->toContain('shadow-premium');
});

it('mounts the theme toggle in the topbar', function (): void {
    $source = themeTopbarSource();

    expect($source)->toContain('ThemeToggle')
        ->toContain("from '@/Components/ThemeToggle'");
});
