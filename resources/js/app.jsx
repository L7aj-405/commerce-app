import { createRoot } from 'react-dom/client';
import { createInertiaApp, router } from '@inertiajs/react';
import applyBrandTokens from '@/Support/applyBrandTokens';
import { applyDensity } from '@/Hooks/useDensity';

// Brand tokens (primary/accent color, font, radius) come from the `brand`
// Inertia prop shared on every request (HandleInertiaRequests) — applied
// once on boot and again after every client-side navigation, so it works
// regardless of which layout (or no layout) a page uses. Not a React
// component: it has no UI and must run independent of any particular
// layout mounting.
router.on('navigate', (event) => applyBrandTokens(event.detail.page.props.brand));

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.jsx', { eager: true });
        const page = pages[`./Pages/${name}.jsx`];

        if (! page) {
            throw new Error(`Missing Inertia page: ${name}. Expected resources/js/Pages/${name}.jsx`);
        }

        return page;
    },
    setup({ el, App, props }) {
        applyDensity();
        applyBrandTokens(props.initialPage?.props?.brand);
        createRoot(el).render(<App {...props} />);
    },
    progress: {
        color: '#6366F1',
        showSpinner: false,
    },
});
