export default function PremiumAppShell({ topbar, sidebar, supportBanner, children, footerLayer }) {
    return (
        <div className="min-h-screen bg-bg p-0 text-text lg:p-5">
            {/*
                `overflow-clip`, NOT `overflow-hidden` — this shell still needs
                to clip content to its own rounded corners, but `hidden`
                (unlike `clip`) makes this div a scroll-containing ancestor,
                which silently breaks `position: sticky` on the topbar
                (FloatingTopbar) the instant the page grows taller than the
                viewport: the header scrolls away instead of sticking. `clip`
                gives the identical visual clipping without that side effect.
                Verified directly (isolated repro): the header vanished on
                scroll under `overflow-hidden` and stayed pinned under
                `overflow-clip`, with nothing else different.
            */}
            <div className="relative mx-auto min-h-screen max-w-[1820px] overflow-clip border border-border bg-canvas shadow-premium lg:min-h-[calc(100vh-2.5rem)] lg:rounded-[34px]">
                {topbar}
                {sidebar}
                {supportBanner}
                <div className="relative lg:pl-[6.75rem]">
                    {children}
                </div>
            </div>
            {footerLayer}
        </div>
    );
}
