/**
 * Small, dependency-free color helpers for the brand appearance settings —
 * no library needed for a hex validator + a luminance-based contrast pick.
 */

export function isValidHex(value) {
    return typeof value === 'string' && /^#[0-9a-fA-F]{6}$/.test(value);
}

function hexToRgb(hex) {
    const n = parseInt(hex.slice(1), 16);
    return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255 };
}

/**
 * WCAG-ish relative luminance — picks readable near-black or white text for
 * an arbitrary background hex (e.g. a custom brand primary color).
 */
export function contrastColor(hex) {
    if (! isValidHex(hex)) return '#ffffff';

    const { r, g, b } = hexToRgb(hex);
    const srgb = [r, g, b].map((c) => {
        const v = c / 255;
        return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
    });
    const luminance = 0.2126 * srgb[0] + 0.7152 * srgb[1] + 0.0722 * srgb[2];

    return luminance > 0.55 ? '#1a1a1a' : '#ffffff';
}
