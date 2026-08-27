import { useEffect, useRef, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { User, Settings, Monitor, LogOut, ChevronDown, ShieldCheck, Palette } from 'lucide-react';

export default function UserDropdown({ tone = 'indigo' }) {
    const { auth } = usePage().props;
    const user     = auth?.user;
    const permissions = auth?.permissions ?? [];
    const can = (permission) => permissions.includes('*') || permissions.includes(permission);
    const canPos = Boolean(auth?.access?.canPos);

    const [open, setOpen] = useState(false);
    const ref             = useRef(null);

    useEffect(() => {
        const onClick = (e) => { if (ref.current && ! ref.current.contains(e.target)) setOpen(false); };
        document.addEventListener('mousedown', onClick);
        return () => document.removeEventListener('mousedown', onClick);
    }, []);

    if (! user) return null;

    const initials = (user.name ?? '?').split(' ').slice(0, 2).map((w) => w[0]).join('').toUpperCase();

    const logout = () => {
        router.post('/logout');
    };

    return (
        <div ref={ref} className="relative">
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                aria-haspopup="menu"
                aria-expanded={open}
                className="flex items-center gap-2 pl-1.5 pr-2 py-1.5 rounded-lg hover:bg-surface-3 transition"
            >
                <div className={`w-8 h-8 rounded-full bg-gradient-to-br ${tone === 'emerald' ? 'from-emerald-500 to-emerald-700' : 'from-indigo-500 to-indigo-700'} text-white text-xs font-bold flex items-center justify-center`}>
                    {initials}
                </div>
                <ChevronDown className="w-3.5 h-3.5 text-content-muted" />
            </button>

            {open && (
                <div
                    role="menu"
                    className="absolute right-0 mt-2 w-60 bg-surface-2 border border-line rounded-xl shadow-2xl z-40 py-1 overflow-hidden"
                >
                    <header className="px-3 py-3 border-b border-line">
                        <div className="text-sm font-semibold text-content truncate">{user.name}</div>
                        <div className="text-xs text-content-muted truncate">{user.email}</div>
                    </header>

                    <MenuLink href="/settings/profile" icon={User}>Profile</MenuLink>
                    <MenuLink href="/settings/security" icon={ShieldCheck}>Security</MenuLink>
                    <MenuLink href="/settings/appearance" icon={Palette}>Appearance</MenuLink>
                    {can('stores.manage') && (
                        <MenuLink href="/dashboard/stores" icon={Settings}>Store settings</MenuLink>
                    )}
                    {canPos && (
                        <MenuLink href="/pos" icon={Monitor}>Switch to POS</MenuLink>
                    )}

                    <div className="my-1 border-t border-line" />

                    <button
                        type="button"
                        onClick={logout}
                        role="menuitem"
                        className="w-full flex items-center gap-2.5 px-3 py-2 text-sm text-red-700 dark:text-red-300 hover:bg-red-500/10 transition"
                    >
                        <LogOut className="w-4 h-4" />
                        Sign out
                    </button>
                </div>
            )}
        </div>
    );
}

function MenuLink({ href, icon: Icon, children }) {
    return (
        <Link
            href={href}
            role="menuitem"
            className="flex items-center gap-2.5 px-3 py-2 text-sm text-content-muted hover:bg-surface-3 hover:text-content transition"
        >
            <Icon className="w-4 h-4 text-content-muted" />
            {children}
        </Link>
    );
}
