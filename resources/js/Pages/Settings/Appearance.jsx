import { Sun, Moon, Monitor } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import SettingsNav from '@/Components/Settings/SettingsNav';
import Card from '@/Components/Card';
import useTheme from '@/Hooks/useTheme';

const OPTIONS = [
    { value: 'light',  label: 'Light',  icon: Sun },
    { value: 'dark',   label: 'Dark',   icon: Moon },
    { value: 'system', label: 'System', icon: Monitor },
];

/**
 * Purely a client-side preference (localStorage + prefers-color-scheme,
 * resources/js/Hooks/useTheme.js) — there's no backend appearance setting to
 * persist, so this page is informational plus the one real mechanism the app
 * already has. Not a second preference system.
 */
export default function Appearance() {
    const { theme, setTheme } = useTheme();

    return (
        <SaasLayout pageHeader={{
            title: 'Settings',
            subtitle: 'Manage your account',
            breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Settings' }],
        }}>
            <SettingsNav current="appearance" />

            <Card title="Appearance" subtitle="Update the appearance settings for your account" className="max-w-2xl">
                <div className="inline-flex items-center gap-1 p-1 rounded-xl bg-surface-3 border border-line">
                    {OPTIONS.map((o) => {
                        const Icon = o.icon;
                        const active = theme === o.value;

                        return (
                            <button
                                key={o.value}
                                type="button"
                                onClick={() => setTheme(o.value)}
                                aria-pressed={active}
                                className={`inline-flex items-center gap-1.5 px-3.5 py-2 text-sm font-medium rounded-lg transition ${
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
        </SaasLayout>
    );
}
