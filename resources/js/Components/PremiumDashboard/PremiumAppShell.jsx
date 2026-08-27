export default function PremiumAppShell({ topbar, sidebar, supportBanner, children, footerLayer }) {
    return (
        <div className="min-h-screen bg-bg p-0 text-text lg:p-5">
            <div className="relative mx-auto min-h-screen max-w-[1820px] overflow-hidden border border-border bg-canvas shadow-premium lg:min-h-[calc(100vh-2.5rem)] lg:rounded-[34px]">
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
