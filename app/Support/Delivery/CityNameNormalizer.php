<?php

declare(strict_types=1);

namespace App\Support\Delivery;

use Illuminate\Support\Str;

/**
 * Shared city-name normalization + alias dictionary, used by BOTH the
 * mapping-suggestion service (Sync Cities UI) and the shipment-time city
 * resolver (Send to Ozon) — they must never drift apart, or a city that the
 * suggestion UI considers "matched" could fail to resolve at send time.
 */
final class CityNameNormalizer
{
    /**
     * Small, deliberately editable alias groups for common Moroccan spelling
     * variants that normalization (accent-stripping/casing) alone doesn't
     * resolve — e.g. "Casa" is a different WORD from "Casablanca", not just
     * a diacritic difference. Each group is a list of already-normalized
     * strings that should be treated as the same city.
     *
     * @var array<int, array<int, string>>
     */
    private const ALIAS_GROUPS = [
        ['casablanca', 'casa'],
        ['marrakech', 'marrakesh'],
        ['fes', 'fez'],
        ['tanger', 'tangier'],
        ['el jadida', 'eljadida'],
    ];

    /** lowercase, accent-stripped, punctuation-normalized, whitespace-collapsed. */
    public static function normalize(string $value): string
    {
        $value = Str::ascii($value);
        $value = mb_strtolower(trim($value));
        $value = str_replace(['-', "'", '’', '.'], ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    /** @return array<int, string>|null every normalized name in $needle's alias group (including itself), or null if it's in none */
    public static function aliasGroupFor(string $needle): ?array
    {
        foreach (self::ALIAS_GROUPS as $group) {
            if (in_array($needle, $group, true)) {
                return $group;
            }
        }

        return null;
    }

    /** similar_text() gives a 0-100 percentage directly — good enough for a conservative fuzzy floor. */
    public static function similarity(string $a, string $b): float
    {
        similar_text($a, $b, $percent);

        return $percent;
    }
}
