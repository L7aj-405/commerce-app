import { contrastColor, isValidHex } from '@/Support/color';

/**
 * Curated, system-safe font stacks (Settings -> Appearance -> Font family).
 * No new font files bundled — 'rounded' only actually renders rounded on
 * Apple platforms (`ui-rounded`), falling back to Inter elsewhere; that's an
 * honest limitation of not shipping a real rounded font.
 */
export const FONT_STACKS = {
    system: 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
    inter: '',
    rounded: 'ui-rounded, "SF Pro Rounded", Inter, ui-sans-serif, sans-serif',
    compact: '"Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
};

/**
 * Applies the store's brand tokens (see App\Support\BrandAppearance, shared
 * on every Inertia page as `brand`) to <html>/<body>. Called once on initial
 * load and again on every Inertia navigation (see app.jsx) — not a React
 * component, since it has no UI and must run before/independent of any
 * particular layout mounting.
 *
 * `primary`/`accent` being null means "use the theme's own default hue" —
 * removing the inline override lets app.css's :root/:root.dark defaults
 * (green light / violet dark) take over again.
 */
export default function applyBrandTokens(brand) {
    if (! brand || typeof document === 'undefined') return;

    const root = document.documentElement;

    if (isValidHex(brand.primary)) {
        root.style.setProperty('--primary', brand.primary);
        root.style.setProperty('--primary-contrast', contrastColor(brand.primary));
    } else {
        root.style.removeProperty('--primary');
        root.style.removeProperty('--primary-contrast');
    }

    if (isValidHex(brand.accent)) {
        root.style.setProperty('--accent', brand.accent);
    } else {
        root.style.removeProperty('--accent');
    }

    root.setAttribute('data-radius', brand.radius ?? 'rounded');
    document.body.style.fontFamily = FONT_STACKS[brand.font] ?? '';
}
