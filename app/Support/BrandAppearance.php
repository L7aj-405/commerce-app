<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Store;
use Illuminate\Validation\Rule;

/**
 * Single source of truth for store-level brand appearance (primary/accent
 * color, font, radius) — read by both Dashboard\SettingsController (the edit
 * form) and HandleInertiaRequests (the global `brand` share every page reads
 * to apply tokens), so the resolved values are never computed twice.
 *
 * Persisted inside the existing `stores.settings` JSON column under a
 * `branding` key — no dedicated migration, mirrors how `tax_rate` already
 * lives there. `primary`/`accent` being null means "use the theme's own
 * default hue" (green light / violet dark) rather than a stored color.
 */
final class BrandAppearance
{
    public const DEFAULT_FONT = 'inter';

    public const DEFAULT_RADIUS = 'rounded';

    /** @var array<int, string> */
    public const FONTS = ['system', 'inter', 'rounded', 'compact'];

    /** @var array<int, string> */
    public const RADII = ['soft', 'rounded', 'pill'];

    private const HEX_PATTERN = '/^#[0-9A-Fa-f]{6}$/';

    /**
     * @return array{primary: ?string, accent: ?string, font: string, radius: string}
     */
    public static function resolve(?Store $store): array
    {
        $branding = $store?->settings['branding'] ?? [];

        $primary = $branding['primary'] ?? null;
        $accent = $branding['accent'] ?? null;
        $font = $branding['font'] ?? null;
        $radius = $branding['radius'] ?? null;

        return [
            'primary' => is_string($primary) && preg_match(self::HEX_PATTERN, $primary) === 1 ? $primary : null,
            'accent' => is_string($accent) && preg_match(self::HEX_PATTERN, $accent) === 1 ? $accent : null,
            'font' => in_array($font, self::FONTS, true) ? $font : self::DEFAULT_FONT,
            'radius' => in_array($radius, self::RADII, true) ? $radius : self::DEFAULT_RADIUS,
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function validationRules(): array
    {
        return [
            'reset' => ['sometimes', 'boolean'],
            'primary' => ['nullable', 'regex:' . self::HEX_PATTERN],
            'accent' => ['nullable', 'regex:' . self::HEX_PATTERN],
            'font' => ['nullable', Rule::in(self::FONTS)],
            'radius' => ['nullable', Rule::in(self::RADII)],
        ];
    }
}
