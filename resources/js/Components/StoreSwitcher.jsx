import { useEffect, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Store as StoreIcon, ChevronsUpDown, Check } from 'lucide-react';
import TypeBadge from '@/Components/TypeBadge';

export default function StoreSwitcher({ collapsed = false }) {
    const { auth } = usePage().props;
    const stores      = auth?.stores ?? [];
    const activeStore = auth?.activeStore ?? null;
    // auth.organization is already scoped to the active store — reuse it
    // instead of re-deriving from `stores` (which may not include the active
    // store's own row if the list came back empty for some reason).
    const activeOrganization = auth?.organization ?? null;

    const [open, setOpen]           = useState(false);
    const [switching, setSwitching] = useState(null);
    const ref                       = useRef(null);

    useEffect(() => {
        const onDocClick = (e) => {
            if (ref.current && ! ref.current.contains(e.target)) setOpen(false);
        };
        document.addEventListener('mousedown', onDocClick);
        return () => document.removeEventListener('mousedown', onDocClick);
    }, []);

    const initials = (name) => (name ?? '?').split(' ').slice(0, 2).map((w) => w[0]).join('').toUpperCase();

    const choose = (id) => {
        if (id === activeStore?.id) { setOpen(false); return; }
        setSwitching(id);
        router.post('/dashboard/stores/switch', { store_id: id }, {
            onFinish: () => { setSwitching(null); setOpen(false); },
        });
    };

    if (stores.length === 0) return null;

    return (
        <div ref={ref} className="relative">
            <button
                type="button"
                onClick={() => stores.length > 1 && setOpen((v) => !v)}
                disabled={stores.length <= 1}
                aria-expanded={open}
                className={`w-full flex items-center gap-2.5 px-2.5 py-2 rounded-lg bg-surface-2 border border-line hover:border-content-muted/40 transition text-left disabled:cursor-default ${
                    collapsed ? 'justify-center' : ''
                }`}
            >
                <div className="flex-shrink-0 w-8 h-8 rounded-md bg-gradient-to-br from-indigo-500 to-indigo-700 text-white text-xs font-bold flex items-center justify-center">
                    {initials(activeStore?.name)}
                </div>
                {! collapsed && (
                    <>
                        <div className="min-w-0 flex-1">
                            <div className="text-[10px] uppercase tracking-wider text-content-muted">Store</div>
                            <div className="flex items-center gap-1.5 min-w-0">
                                <div className="text-sm font-semibold text-content truncate">{activeStore?.name ?? 'Select store'}</div>
                                <TypeBadge kind="organization" value={activeOrganization?.type} />
                            </div>
                            {activeOrganization?.name && (
                                <div className="text-[11px] text-content-muted truncate">{activeOrganization.name}</div>
                            )}
                        </div>
                        {stores.length > 1 && <ChevronsUpDown className="w-4 h-4 text-content-muted flex-shrink-0" />}
                    </>
                )}
            </button>

            {open && (
                <ul
                    role="listbox"
                    className="absolute left-0 right-0 mt-1.5 bg-surface-2 border border-line rounded-lg shadow-2xl z-40 py-1 max-h-72 overflow-y-auto"
                >
                    {stores.map((s) => {
                        const isActive    = s.id === activeStore?.id;
                        const isSwitching = switching === s.id;
                        return (
                            <li key={s.id}>
                                <button
                                    type="button"
                                    role="option"
                                    aria-selected={isActive}
                                    disabled={isSwitching}
                                    onClick={() => choose(s.id)}
                                    className={`w-full flex items-center gap-2 px-2.5 py-2 text-sm transition ${
                                        isActive ? 'text-indigo-700 dark:text-indigo-300' : 'text-content-muted hover:bg-surface-3'
                                    }`}
                                >
                                    <div className="w-6 h-6 rounded-md bg-gradient-to-br from-indigo-500/40 to-indigo-700/40 text-white text-[10px] font-bold flex items-center justify-center flex-shrink-0">
                                        {initials(s.name)}
                                    </div>
                                    <span className="flex-1 min-w-0 text-left">
                                        <span className="flex items-center gap-1.5">
                                            <span className="truncate">{s.name}</span>
                                            <TypeBadge kind="organization" value={s.organization?.type} />
                                        </span>
                                        {s.organization?.name && (
                                            <span className="block text-[11px] text-content-muted truncate">{s.organization.name}</span>
                                        )}
                                    </span>
                                    {isSwitching ? (
                                        <span className="w-3 h-3 rounded-full border-2 border-content-muted/40 border-t-indigo-500 animate-spin" />
                                    ) : isActive ? (
                                        <Check className="w-4 h-4 text-indigo-600 dark:text-indigo-400" />
                                    ) : null}
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}
