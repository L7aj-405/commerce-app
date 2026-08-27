import { Loader2 } from 'lucide-react';

// Codifies the button classes already used consistently across
// Login/ForgotPassword/Merchant onboarding/Confirmation — not a new look,
// just one place to write it instead of copy-pasting the class string.
// Colors/radius route through tokens (--primary, --radius-button) so brand
// color and radius settings (Settings -> Appearance) actually apply here.
const VARIANTS = {
    primary:   'bg-primary text-primary-contrast hover:bg-primary-strong',
    secondary: 'bg-surface-2 border border-line text-content hover:bg-surface-3',
    danger:    'bg-danger text-white hover:brightness-90',
    ghost:     'text-content-muted hover:text-content hover:bg-surface-2',
};

export default function Button({ variant = 'primary', loading = false, disabled = false, icon: Icon, children, className = '', ...props }) {
    return (
        <button
            disabled={disabled || loading}
            className={`inline-flex items-center justify-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-[var(--radius-button)] transition disabled:opacity-50 disabled:cursor-not-allowed ${VARIANTS[variant] ?? VARIANTS.primary} ${className}`}
            {...props}
        >
            {loading ? <Loader2 className="w-4 h-4 animate-spin" /> : (Icon && <Icon className="w-4 h-4" />)}
            {children}
        </button>
    );
}
