import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Sun, Moon, Monitor, Rows3, Rows2, Check, RotateCcw, Save, Loader2, ShoppingBag } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import SettingsNav from '@/Components/Settings/SettingsNav';
import Card from '@/Components/Card';
import StatusBadge from '@/Components/StatusBadge';
import useTheme from '@/Hooks/useTheme';
import useDensity from '@/Hooks/useDensity';
import { contrastColor, isValidHex } from '@/Support/color';

const THEME_OPTIONS = [
    { value: 'light',  label: 'Light',  icon: Sun },
    { value: 'dark',   label: 'Dark',   icon: Moon },
    { value: 'system', label: 'System', icon: Monitor },
];

const DENSITY_OPTIONS = [
    { value: 'comfortable', label: 'Comfortable', icon: Rows3 },
    { value: 'compact',     label: 'Compact',     icon: Rows2 },
];

const PRESETS = [
    { key: 'emerald', label: 'Emerald', hex: '#118858' },
    { key: 'indigo',  label: 'Indigo',  hex: '#4f46e5' },
    { key: 'purple',  label: 'Purple',  hex: '#7c3aed' },
    { key: 'blue',    label: 'Blue',    hex: '#2563eb' },
    { key: 'orange',  label: 'Orange',  hex: '#ea580c' },
    { key: 'rose',    label: 'Rose',    hex: '#e11d48' },
    { key: 'slate',   label: 'Slate',   hex: '#475569' },
];

const FONT_OPTIONS = [
    { value: 'system',  label: 'System UI',        hint: 'Your device\'s native font.' },
    { value: 'inter',   label: 'Inter (default)',  hint: 'The app\'s current default.' },
    { value: 'rounded', label: 'Rounded UI',        hint: 'Softer, friendlier letterforms (Apple devices only — falls back to Inter elsewhere).' },
    { value: 'compact', label: 'Compact business',  hint: 'Tighter, denser system stack.' },
];

const RADIUS_OPTIONS = [
    { value: 'soft',    label: 'Soft' },
    { value: 'rounded', label: 'Rounded' },
    { value: 'pill',    label: 'Pill' },
];

// Mirrors app.css's :root[data-radius="…"] values exactly, so the live
// preview never drifts from what Save will actually apply.
const RADIUS_VALUES = {
    soft:    { card: '0.75rem', button: '0.5rem' },
    rounded: { card: '1rem',    button: '0.625rem' },
    pill:    { card: '1.5rem',  button: '9999px' },
};

/**
 * Unified Appearance page. Theme mode + density are personal, client-only
 * preferences (useTheme.js / useDensity.js) editable by anyone. The Brand &
 * Store Appearance section only renders for users holding `settings.manage`
 * (same gate as the rest of Store Settings — Manager's default role doesn't
 * have it, so this is already effectively owner/admin-only).
 */
export default function Appearance() {
    const { theme, setTheme } = useTheme();
    const { density, setDensity } = useDensity();
    const { auth, brand } = usePage().props;
    const permissions = auth?.permissions ?? [];
    const canManageBrand = permissions.includes('*') || permissions.includes('settings.manage');

    return (
        <SaasLayout pageHeader={{
            title: 'Settings',
            subtitle: 'Manage your account and store appearance',
            breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Settings' }],
        }}>
            <SettingsNav current="appearance" />

            <div className="max-w-3xl space-y-6">
                <Card title="Theme mode" subtitle="Choose how the app looks on this device.">
                    <div className="inline-flex items-center gap-1 p-1 rounded-[var(--radius-button)] bg-surface-3 border border-line">
                        {THEME_OPTIONS.map((o) => {
                            const Icon = o.icon;
                            const active = theme === o.value;
                            return (
                                <button
                                    key={o.value}
                                    type="button"
                                    onClick={() => setTheme(o.value)}
                                    aria-pressed={active}
                                    className={`inline-flex items-center gap-1.5 px-3.5 py-2 text-sm font-medium rounded-[var(--radius-button)] transition ${
                                        active ? 'bg-surface text-content shadow-sm border border-line' : 'text-content-muted hover:text-content'
                                    }`}
                                >
                                    <Icon className="w-4 h-4" />
                                    {o.label}
                                </button>
                            );
                        })}
                    </div>
                    <p className="mt-3 text-xs text-content-muted">
                        "System" follows your device's light/dark setting automatically.
                    </p>
                </Card>

                <Card title="Density" subtitle="Adjust spacing in tables, cards and filter bars.">
                    <div className="inline-flex items-center gap-1 p-1 rounded-[var(--radius-button)] bg-surface-3 border border-line">
                        {DENSITY_OPTIONS.map((o) => {
                            const Icon = o.icon;
                            const active = density === o.value;
                            return (
                                <button
                                    key={o.value}
                                    type="button"
                                    onClick={() => setDensity(o.value)}
                                    aria-pressed={active}
                                    className={`inline-flex items-center gap-1.5 px-3.5 py-2 text-sm font-medium rounded-[var(--radius-button)] transition ${
                                        active ? 'bg-surface text-content shadow-sm border border-line' : 'text-content-muted hover:text-content'
                                    }`}
                                >
                                    <Icon className="w-4 h-4" />
                                    {o.label}
                                </button>
                            );
                        })}
                    </div>
                    <p className="mt-3 text-xs text-content-muted">
                        Compact tightens table rows, card padding and filter-bar spacing.
                    </p>
                </Card>

                {canManageBrand ? (
                    <BrandSection initialBrand={brand} />
                ) : (
                    <Card title="Brand & Store Appearance" subtitle="Primary color, font and radius for the whole store.">
                        <p className="text-sm text-content-muted">
                            Brand appearance is managed by your store owner or admin.
                        </p>
                    </Card>
                )}
            </div>
        </SaasLayout>
    );
}

function BrandSection({ initialBrand }) {
    const { data, setData, patch, processing, errors } = useForm({
        primary: initialBrand?.primary ?? '',
        accent: initialBrand?.accent ?? '',
        font: initialBrand?.font ?? 'inter',
        radius: initialBrand?.radius ?? 'rounded',
    });
    const [resetting, setResetting] = useState(false);

    const primaryValid = data.primary === '' || isValidHex(data.primary);
    const accentValid = data.accent === '' || isValidHex(data.accent);
    const canSubmit = primaryValid && accentValid && ! processing;

    const applyPreset = (hex) => setData((d) => ({ ...d, primary: hex, accent: hex }));

    const submit = (e) => {
        e.preventDefault();
        if (! canSubmit) return;
        patch('/dashboard/settings/branding', { preserveScroll: true });
    };

    const resetToDefault = () => {
        setResetting(true);
        router.patch('/dashboard/settings/branding', { reset: true }, {
            preserveScroll: true,
            onSuccess: () => setData({ primary: '', accent: '', font: 'inter', radius: 'rounded' }),
            onFinish: () => setResetting(false),
        });
    };

    const previewPrimary = primaryValid && data.primary ? data.primary : '#118858';
    const radiusValues = RADIUS_VALUES[data.radius] ?? RADIUS_VALUES.rounded;

    return (
        <Card title="Brand & Store Appearance" subtitle="Applies to every user viewing this store, in both light and dark mode.">
            <form onSubmit={submit} className="space-y-5">
                <div>
                    <label className="block text-sm font-medium text-content-muted mb-2">Preset palette</label>
                    <div className="flex flex-wrap gap-2">
                        {PRESETS.map((p) => (
                            <button
                                key={p.key}
                                type="button"
                                onClick={() => applyPreset(p.hex)}
                                title={p.label}
                                aria-label={`Use ${p.label}`}
                                className="group flex flex-col items-center gap-1"
                            >
                                <span
                                    className="flex h-9 w-9 items-center justify-center rounded-full ring-2 ring-offset-2 ring-offset-surface-2 transition"
                                    style={{ backgroundColor: p.hex, '--tw-ring-color': data.primary === p.hex ? p.hex : 'transparent' }}
                                >
                                    {data.primary === p.hex && <Check className="h-4 w-4 text-white" />}
                                </span>
                                <span className="text-[11px] text-content-muted group-hover:text-content">{p.label}</span>
                            </button>
                        ))}
                    </div>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <HexField label="Primary color" value={data.primary} onChange={(v) => setData('primary', v)} valid={primaryValid} error={errors.primary} />
                    <HexField label="Accent color" value={data.accent} onChange={(v) => setData('accent', v)} valid={accentValid} error={errors.accent} placeholder="Same as primary" />
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label className="block text-sm font-medium text-content-muted mb-1.5">Font family</label>
                        <select
                            value={data.font}
                            onChange={(e) => setData('font', e.target.value)}
                            className="w-full px-3 py-2 text-sm rounded-[var(--radius-button)] bg-surface-3 border border-line text-content focus:outline-none focus:ring-2 focus:ring-primary"
                        >
                            {FONT_OPTIONS.map((f) => <option key={f.value} value={f.value}>{f.label}</option>)}
                        </select>
                        <p className="mt-1 text-xs text-content-muted">
                            {FONT_OPTIONS.find((f) => f.value === data.font)?.hint}
                        </p>
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-content-muted mb-1.5">Border radius</label>
                        <div className="grid grid-cols-3 gap-2">
                            {RADIUS_OPTIONS.map((r) => {
                                const active = data.radius === r.value;
                                return (
                                    <button
                                        key={r.value}
                                        type="button"
                                        onClick={() => setData('radius', r.value)}
                                        aria-pressed={active}
                                        className={`flex flex-col items-center gap-1.5 py-2 border transition ${
                                            active ? 'border-primary bg-primary-soft' : 'border-line hover:border-content-muted/40'
                                        }`}
                                        style={{ borderRadius: RADIUS_VALUES[r.value].card }}
                                    >
                                        <span
                                            className="h-4 w-8 bg-content-muted/30"
                                            style={{ borderRadius: RADIUS_VALUES[r.value].button }}
                                        />
                                        <span className="text-[11px] text-content-muted">{r.label}</span>
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                </div>

                {/* Live preview — scoped to this wrapper only via inline CSS
                    custom properties, so it never touches the rest of the
                    open page until Save. */}
                <div>
                    <label className="block text-sm font-medium text-content-muted mb-2">Live preview</label>
                    <div
                        className="rounded-[var(--radius-card)] border border-line bg-surface p-4"
                        style={{
                            '--primary': previewPrimary,
                            '--primary-contrast': contrastColor(previewPrimary),
                            '--radius-card': radiusValues.card,
                            '--radius-button': radiusValues.button,
                        }}
                    >
                        <div className="flex flex-wrap items-center gap-3">
                            <button type="button" className="btn-primary" onClick={(e) => e.preventDefault()}>
                                <ShoppingBag className="w-4 h-4" /> Primary action
                            </button>
                            <StatusBadge type="fulfillment" status="delivered" />
                            <StatusBadge type="fulfillment" status="pending" />
                        </div>

                        <div className="mt-3 card-sm">
                            <p className="text-xs font-medium text-content-muted mb-1.5">Modal field</p>
                            <input className="input" placeholder="Customer name" readOnly />
                        </div>

                        <div className="mt-3 table-container">
                            <table className="w-full text-sm">
                                <thead><tr><th className="table-th">Reference</th><th className="table-th">Status</th></tr></thead>
                                <tbody>
                                    <tr className="table-row"><td className="table-td">ORD-0001</td><td className="table-td"><StatusBadge type="fulfillment" status="confirmed" /></td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div className="flex items-center gap-2 pt-1">
                    <button type="submit" disabled={! canSubmit} className="btn-primary">
                        {processing ? <><Loader2 className="w-4 h-4 animate-spin" /> Saving…</> : <><Save className="w-4 h-4" /> Save brand appearance</>}
                    </button>
                    <button type="button" onClick={resetToDefault} disabled={resetting} className="btn-secondary">
                        {resetting ? <Loader2 className="w-4 h-4 animate-spin" /> : <RotateCcw className="w-4 h-4" />} Reset to default
                    </button>
                </div>
            </form>
        </Card>
    );
}

function HexField({ label, value, onChange, valid, error, placeholder = '#118858' }) {
    return (
        <div>
            <label className="block text-sm font-medium text-content-muted mb-1.5">{label}</label>
            <div className="flex items-center gap-2">
                <span
                    className="h-9 w-9 flex-shrink-0 rounded-[var(--radius-button)] border border-line"
                    style={{ backgroundColor: valid && value ? value : 'transparent' }}
                />
                <input
                    type="text"
                    value={value}
                    onChange={(e) => onChange(e.target.value.trim())}
                    placeholder={placeholder}
                    spellCheck={false}
                    className={`flex-1 px-3 py-2 text-sm font-mono rounded-[var(--radius-button)] bg-surface-3 border text-content focus:outline-none focus:ring-2 focus:ring-primary ${
                        valid ? 'border-line' : 'border-danger'
                    }`}
                />
            </div>
            {! valid && <p className="mt-1 text-xs text-danger">Enter a valid 6-digit hex color (e.g. #118858), or leave blank.</p>}
            {error && <p className="mt-1 text-xs text-danger">{error}</p>}
        </div>
    );
}
