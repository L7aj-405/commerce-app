/**
 * Accessible dual-thumb range slider built from two native <input type="range">.
 * Native inputs give keyboard + screen-reader support for free; the `.range-input`
 * CSS (in app.css) makes the track transparent and only the thumbs interactive,
 * so the two overlaid rails read as one. A min gap of `step` keeps thumbs apart.
 */
export default function PriceRangeSlider({ min, max, step = 1, value, onChange, format = (v) => v }) {
    const [lo, hi] = value;
    const pct = (v) => (max === min ? 0 : ((v - min) / (max - min)) * 100);

    const setLo = (raw) => onChange([Math.min(Number(raw), hi - step), hi]);
    const setHi = (raw) => onChange([lo, Math.max(Number(raw), lo + step)]);

    return (
        <div className="pt-1">
            <div className="flex items-center justify-between text-xs text-content-muted mb-2">
                <span className="tabular-nums text-content">{format(lo)}</span>
                <span className="tabular-nums text-content">{format(hi)}</span>
            </div>

            <div className="relative h-4">
                {/* Rail */}
                <div className="absolute inset-x-0 top-1/2 -translate-y-1/2 h-1 rounded-full bg-content/10" />
                {/* Selected span */}
                <div
                    className="absolute top-1/2 -translate-y-1/2 h-1 rounded-full bg-indigo-500"
                    style={{ left: `${pct(lo)}%`, right: `${100 - pct(hi)}%` }}
                />

                <input
                    type="range"
                    className="range-input absolute inset-0 w-full"
                    min={min}
                    max={max}
                    step={step}
                    value={lo}
                    onChange={(e) => setLo(e.target.value)}
                    aria-label="Minimum price"
                    aria-valuetext={String(format(lo))}
                />
                <input
                    type="range"
                    className="range-input absolute inset-0 w-full"
                    min={min}
                    max={max}
                    step={step}
                    value={hi}
                    onChange={(e) => setHi(e.target.value)}
                    aria-label="Maximum price"
                    aria-valuetext={String(format(hi))}
                />
            </div>
        </div>
    );
}
