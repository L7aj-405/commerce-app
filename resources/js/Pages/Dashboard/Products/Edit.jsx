import { useState, useEffect } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Loader2, Save, Trash2, Plus, Layers, Package, Settings, CheckCircle, Upload, SlidersHorizontal } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import Card from '@/Components/Card';
import StatusBadge from '@/Components/StatusBadge';
import AdjustStockModal from '@/Components/Products/AdjustStockModal';
import PublishTargetModal from '@/Components/Products/PublishTargetModal';

export default function Edit({ product, connections = [], warehouses = [], readiness = {} }) {
    const permissions = usePage().props.auth?.permissions ?? [];
    const canAdjustStock = permissions.includes('*') || permissions.includes('stock.adjust');
    const [adjusting, setAdjusting] = useState(null); // null | { variantId, variantName }

    // Canonical option definitions come straight from the backend
    // (ProductAttribute/ProductAttributeValue) — no more inferring them
    // from whatever happened to survive on each variant's JSON blob.
    const buildOptionsFromProduct = (product) => {
        return (product?.options ?? []).map((o) => ({ name: o.name, values: [...(o.values ?? [])] }));
    };

    // A combination's real identity is its sorted set of option:value pairs
    // — never the display title, and never insertion order (adding a new
    // option used to reshuffle Object.values() order and silently break
    // matching, which is the client-side half of the reported bug).
    const comboKeyFor = (optionsMap) => Object.entries(optionsMap || {})
        .map(([k, v]) => `${k}:${v}`)
        .sort()
        .join('|');

    // Stock is intentionally NOT part of the form payload — it's read-only
    // here and only ever changed through the inventory-safe Adjust Stock
    // flow (POST .../stock). Keeping it out of `data.variants` means it can
    // never be sent back on save and never gets reset by generate/save.
    const prepareVariantsFromProduct = (variants) => {
        if (!variants || !Array.isArray(variants)) return [];
        return variants.map(variant => {
            const options = variant.options ?? {};

            return {
                id: variant.id || null,
                combo_key: comboKeyFor(options),
                options,
                sku: variant.sku ?? '',
                price: variant.price ? String(variant.price) : '',
                cost: variant.cost ? String(variant.cost) : '',
                selected: true,
                channel_listings: variant.channel_listings ?? [],
            };
        });
    };

    // Always reads the live `product` prop — never the form's `data` state —
    // so a partial Inertia reload after an Adjust Stock action shows the new
    // number immediately without disturbing any in-progress unsaved edits.
    const stockForVariant = (variantId) => {
        if (!variantId) return 0;
        const pv = (product?.variants ?? []).find((v) => v.id === variantId);
        if (!pv) return 0;
        if (Array.isArray(pv.stocks)) return pv.stocks.reduce((sum, s) => sum + (parseInt(s.quantity) || 0), 0);
        return parseInt(pv.qty ?? pv.stock ?? 0) || 0;
    };

    // 3. إعداد الـ Form مع إضافة حقل qty الموحد للـ Simple Product
    const { data, setData, patch, processing, errors } = useForm({
        name: product?.name ?? '', 
        sku: product?.sku ?? '',
        description: product?.description ?? '', 
        category: product?.category ?? '',
        price: product?.price ?? '', 
        compare_price: product?.compare_price ?? '',
        cost: product?.cost ?? '', 
        featured_image: product?.featured_image ?? '',
        status: product?.status ?? 'active',
        type: product?.type ? String(product.type).toLowerCase().trim() : 'simple',

        options: buildOptionsFromProduct(product),
        variants: prepareVariantsFromProduct(product?.variants),
        regenerate_skus: false,
    });

    // ✨ تحديث البيانات تلقائياً أول ما يتشارجا الـ Product
    useEffect(() => {
        if (product) {
            setData(prev => ({
                ...prev,
                name: product.name ?? '',
                sku: product.sku ?? '',
                description: product.description ?? '',
                category: product.category ?? '',
                price: product.price ?? '',
                compare_price: product.compare_price ?? '',
                cost: product.cost ?? '',
                status: product.status ?? 'active',
                type: product.type ? String(product.type).toLowerCase().trim() : 'simple',

                options: buildOptionsFromProduct(product),
                variants: prepareVariantsFromProduct(product.variants),
                regenerate_skus: false,
            }));
        }
    }, [product?.id]);

    // Controls للـ Wizard خطوات التعديل
    const [step, setStep] = useState(1);
    const maxSteps = 4;
    const stepLabels = ['Product Type', 'Basic Info', 'Pricing & Variants', 'Status & Media'];

    const [currentAttrName, setCurrentAttrName] = useState('');
    const [currentAttrValues, setCurrentAttrValues] = useState('');

    const profit = (parseFloat(data.price) || 0) - (parseFloat(data.cost) || 0);
    const profitMargin = data.price > 0 ? ((profit / parseFloat(data.price)) * 100).toFixed(2) : '0.00';

    // ============================================
    // الـ LOGIC الخاصة بالـ VARIANTS & ATTRIBUTES
    // ============================================

    const handleAddAttribute = (e) => {
        e.preventDefault();
        if (!currentAttrName.trim() || !currentAttrValues.trim()) return;

        const valuesArray = currentAttrValues.split(',').map(v => v.trim()).filter(v => v !== '');
        if (data.options.some(a => a.name.toLowerCase() === currentAttrName.trim().toLowerCase())) return;

        setData('options', [...data.options, { name: currentAttrName.trim(), values: valuesArray }]);
        setCurrentAttrName('');
        setCurrentAttrValues('');
    };

    const handleRenameOption = (index, newName) => {
        const trimmed = newName.trim();
        if (trimmed === '') return;

        setData(prev => {
            const oldName = prev.options[index]?.name;
            const nextOptions = prev.options.map((o, i) => (i === index ? { ...o, name: trimmed } : o));

            if (!oldName || oldName === trimmed) {
                return { ...prev, options: nextOptions };
            }

            // Update options + variant maps in one state transaction. Doing two
            // setData calls here can race and leave the form with renamed
            // options but variants still carrying the old option key.
            const nextVariants = (prev.variants ?? []).map((v) => {
                if (!(oldName in (v.options ?? {}))) return v;
                const { [oldName]: value, ...rest } = v.options;
                const options = { ...rest, [trimmed]: value };
                return { ...v, options, combo_key: comboKeyFor(options) };
            });

            return { ...prev, options: nextOptions, variants: nextVariants };
        });
    };

    const handleRemoveAttribute = (indexToRemove) => {
        setData(prev => {
            const removed = prev.options[indexToRemove];
            const nextOptions = prev.options.filter((_, index) => index !== indexToRemove);

            // A variant containing a removed option is no longer a valid active
            // combination. Drop it from the active form immediately; the backend
            // will archive rather than hard-delete if it has listings/inventory.
            const nextVariants = removed
                ? (prev.variants ?? []).filter((v) => !(removed.name in (v.options ?? {})))
                : (prev.variants ?? []);

            return { ...prev, options: nextOptions, variants: nextVariants };
        });
    };

    const handleAddOptionValue = (optionIndex, rawValue) => {
        const value = rawValue.trim();
        if (value === '') return;
        const updated = data.options.map((o, i) => {
            if (i !== optionIndex) return o;
            if (o.values.some((v) => v.toLowerCase() === value.toLowerCase())) return o;
            return { ...o, values: [...o.values, value] };
        });
        setData('options', updated);
    };

    // Safe to remove client-side only when no generated variant currently
    // uses this value — the backend re-checks (channel listings / inventory
    // links) regardless, this is just an honest heads-up before saving.
    const handleRemoveOptionValue = (optionIndex, valueIndex) => {
        setData(prev => {
            const option = prev.options[optionIndex];
            const value = option?.values?.[valueIndex];
            if (!option || value === undefined) return prev;

            const inUse = (prev.variants ?? []).some((v) => v.options?.[option.name] === value);

            if (inUse && !confirm(`"${value}" is used by an existing variant. Removing it will drop that combination from the active list. Linked variants are archived safely on save. Continue?`)) {
                return prev;
            }

            const nextOptions = prev.options.map((o, i) => (
                i === optionIndex ? { ...o, values: o.values.filter((_, vi) => vi !== valueIndex) } : o
            ));

            // Do not wait for the user to click Generate. Once a value is
            // removed, every active variant containing it is invalid and should
            // disappear immediately from the form payload.
            const nextVariants = (prev.variants ?? []).filter((v) => v.options?.[option.name] !== value);

            return { ...prev, options: nextOptions, variants: nextVariants };
        });
    };

    const skuFragment = (value) => {
        const fragment = String(value ?? '')
            .trim()
            .replace(/[^A-Za-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .toUpperCase();

        return fragment || 'X';
    };

    const generateVariantSku = (options, usedSkus = new Set()) => {
        const base = skuFragment(data.sku || data.name || product?.id?.slice(0, 8) || 'PRODUCT');
        const suffix = (data.options ?? [])
            .map((option) => skuFragment(options?.[option.name]))
            .join('-');
        const natural = [base, suffix].filter(Boolean).join('-');
        let candidate = natural;
        let suffixNumber = 2;

        while (usedSkus.has(candidate)) {
            candidate = `${natural}-${suffixNumber}`;
            suffixNumber += 1;
        }

        usedSkus.add(candidate);
        return candidate;
    };

    const generateVariantsMatrix = () => {
        if (data.options.length === 0) return;

        const cartesian = (sets) => sets.reduce((acc, set) => acc.flatMap(x => set.map(y => [...x, y])), [[]]);
        const sets = data.options.map(attr => attr.values.map(val => ({ [attr.name]: val })));

        if (sets.some(s => s.length === 0)) {
            alert('المرجو إدخال قيم لكل خاصية أولاً');
            return;
        }

        const combinations = cartesian(sets);

        // Reserve non-empty SKUs from the current form, including variants that
        // may be dropped by this regeneration. The backend is still the final
        // authority, but this keeps the UI preview close to reality.
        const usedSkus = new Set((data.variants ?? []).map((v) => v.sku).filter(Boolean));

        const generatedVariants = combinations.map(combo => {
            const options = Object.assign({}, ...combo);
            const comboKey = comboKeyFor(options);

            // Match by the canonical (sorted) combination — order-independent,
            // so adding/removing options no longer loses rows whose exact
            // combination still exists.
            const existingVariant = (data.variants ?? []).find(v => v.combo_key === comboKey);

            if (existingVariant) {
                // Shopify imports often have empty variant SKUs. Generate a
                // visible SKU immediately when the field is empty, but never
                // overwrite a user/platform SKU here. The Regenerate SKUs action
                // is the explicit overwrite path.
                if (!existingVariant.sku) {
                    return { ...existingVariant, options, combo_key: comboKey, sku: generateVariantSku(options, usedSkus) };
                }

                usedSkus.add(existingVariant.sku);
                return { ...existingVariant, options, combo_key: comboKey };
            }

            return {
                id: null,
                combo_key: comboKey,
                options,
                sku: generateVariantSku(options, usedSkus),
                price: data.price || '',
                cost: data.cost || '',
                selected: true,
                channel_listings: [],
            };
        });

        setData(prev => ({ ...prev, variants: generatedVariants }));
    };

    const handleVariantChange = (index, field, value) => {
        const updatedVariants = [...data.variants];
        updatedVariants[index][field] = value;
        setData('variants', updatedVariants);
    };

    const submit = (e) => {
        e.preventDefault();
        patch(`/dashboard/products/${product.id}`);
    };

    // Regenerates every active variant's SKU, overwriting existing ones —
    // the actual generation (base SKU + normalized option values + uniqueness
    // suffix) happens server-side in ProductVariantWizardService, this just
    // flags the same save payload to request it.
    const handleRegenerateSkus = () => {
        if (!confirm('Regenerate SKUs for all active variants? This overwrites any SKU already set.')) return;

        const usedSkus = new Set();
        const regenerated = (data.variants ?? []).map((variant) => ({
            ...variant,
            sku: generateVariantSku(variant.options ?? {}, usedSkus),
        }));

        // Keep the flag for the server-side save. This gives the user instant
        // feedback in the table, while ProductVariantWizardService still does
        // the authoritative unique SKU generation during save.
        setData(prev => ({ ...prev, variants: regenerated, regenerate_skus: true }));
    };

    const nextStep = () => step < maxSteps && setStep(step + 1);
    const prevStep = () => step > 1 && setStep(step - 1);

    // Publishing never fires immediately — it opens the target-selection
    // modal so the user explicitly picks which connection(s) to push to.
    // Clicking "Publish to platforms" used to push to every active
    // connection for the store, including platforms the user never chose.
    const [publishModalOpen, setPublishModalOpen] = useState(false);

    return (
        <SaasLayout pageHeader={{
            title: `Edit ${data.name || product?.name}`,
            breadcrumbs: [
                { label: 'Dashboard', href: '/dashboard' },
                { label: 'Products',  href: '/dashboard/products' },
                { label: 'Edit' },
            ],
            actions: (
                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        onClick={() => setPublishModalOpen(true)}
                        disabled={connections.length === 0}
                        title={connections.length === 0 ? 'No active platform connections for this store.' : undefined}
                        className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed transition"
                    >
                        <Upload className="w-4 h-4" />
                        Publish to platforms
                    </button>
                    <Link href="/dashboard/products" className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface-3 border border-line text-content-muted hover:bg-content/10 hover:text-content transition">
                        <ArrowLeft className="w-4 h-4" /> Back
                    </Link>
                </div>
            ),
        }}>
            
            <div className="max-w-4xl mx-auto space-y-6">

                <PublishReadinessPanel readiness={readiness} />

                {/* --- STEP INDICATOR TRAIL --- */}
                <div className="bg-surface-2 border border-line py-4 px-6 rounded-xl shadow-sm">
                    <div className="flex items-center justify-between">
                        {stepLabels.map((label, i) => {
                            const num = i + 1;
                            return (
                                <div key={num} className="flex items-center flex-1 last:flex-none">
                                    <button
                                        type="button"
                                        onClick={() => setStep(num)}
                                        className="flex items-center gap-2.5 focus:outline-none text-left"
                                    >
                                        <div className={`w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold border transition-all ${
                                            num === step 
                                                ? 'bg-indigo-600 border-indigo-500 text-white ring-4 ring-indigo-500/10' 
                                                : num < step 
                                                ? 'bg-indigo-950 border-indigo-500/50 text-indigo-600 dark:text-indigo-400' 
                                                : 'bg-surface border-line text-content-muted'
                                        }`}>
                                            {num < step ? <CheckCircle className="w-4 h-4" /> : num}
                                        </div>
                                        <span className={`text-xs font-medium hidden md:inline whitespace-nowrap ${
                                            num === step ? 'text-white' : 'text-content-muted'
                                        }`}>
                                            {label}
                                        </span>
                                    </button>
                                    {i < stepLabels.length - 1 && (
                                        <div className={`h-px flex-1 mx-4 ${num < step ? 'bg-indigo-500/50' : 'bg-surface-3'}`} />
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </div>

                {/* --- MAIN INTERFACE CONTAINER --- */}
                <form onSubmit={submit} className="bg-surface-2 border border-line rounded-xl p-6 space-y-6 shadow-xl">
                    
                    {/* STEP 1: PRODUCT TYPE CONFIGURATION */}
                    {step === 1 && (
                        <div className="space-y-4 animate-fadeIn">
                            <Section title="Product Structural Type">
                                <p className="text-xs text-content-muted mb-4 font-mono">Current Type In DB: <span className="text-indigo-600 dark:text-indigo-400">{data.type}</span></p>
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div 
                                        onClick={() => setData('type', 'simple')}
                                        className={`relative p-5 rounded-xl border-2 cursor-pointer transition-all flex gap-4 ${
                                            data.type === 'simple'
                                                ? 'border-indigo-500 bg-indigo-500/5 text-white'
                                                : 'border-line bg-surface/50 text-content-muted hover:border-line'
                                        }`}
                                    >
                                        <Package className={`w-8 h-8 flex-shrink-0 ${data.type === 'simple' ? 'text-indigo-600 dark:text-indigo-400' : 'text-content-muted/60'}`} />
                                        <div>
                                            <p className="text-sm font-semibold">Simple Product</p>
                                            <p className="text-xs text-content-muted mt-1">منتج عادي برقم SKU واحد وثمن موحد.</p>
                                        </div>
                                    </div>

                                    <div 
                                        onClick={() => setData('type', 'variable')}
                                        className={`relative p-5 rounded-xl border-2 cursor-pointer transition-all flex gap-4 ${
                                            data.type === 'variable'
                                                ? 'border-indigo-500 bg-indigo-500/5 text-white'
                                                : 'border-line bg-surface/50 text-content-muted hover:border-line'
                                        }`}
                                    >
                                        <Settings className={`w-8 h-8 flex-shrink-0 ${data.type === 'variable' ? 'text-indigo-600 dark:text-indigo-400' : 'text-content-muted/60'}`} />
                                        <div>
                                            <p className="text-sm font-semibold">Variable Product</p>
                                            <p className="text-xs text-content-muted mt-1">منتج بخصائص متعددة وجدول فاريانتس ديناميكي.</p>
                                        </div>
                                    </div>
                                </div>
                            </Section>
                        </div>
                    )}

                    {/* STEP 2: BASIC GLOBAL METADATA */}
                    {step === 2 && (
                        <div className="space-y-4 animate-fadeIn">
                            <Section title="Basic Global Information">
                                <Field label="Name" value={data.name} onChange={(v) => setData('name', v)} error={errors.name} required />
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <Field label="SKU" value={data.sku} onChange={(v) => setData('sku', v)} error={errors.sku} required />
                                    <Field label="Category" value={data.category} onChange={(v) => setData('category', v)} error={errors.category} />
                                </div>
                                <TextArea label="Description" value={data.description} onChange={(v) => setData('description', v)} error={errors.description} />
                            </Section>
                        </div>
                    )}

                    {/* STEP 3: FINANCIAL DATA & MATRIX PERMUTATIONS */}
                    {step === 3 && (
                        <div className="space-y-6 animate-fadeIn">
                            
                            {/* Base Pricing Panel */}
                            <Section title="Core Financial Pricing Rules">
                                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <Field label="Base Price (MAD)" type="number" step="0.01" value={data.price} onChange={(v) => setData('price', v)} error={errors.price} required />
                                    <Field label="Compare price" type="number" step="0.01" value={data.compare_price} onChange={(v) => setData('compare_price', v)} error={errors.compare_price} />
                                    <Field label="Cost" type="number" step="0.01" value={data.cost} onChange={(v) => setData('cost', v)} error={errors.cost} />
                                </div>

                                {/* Read-only — quantity is no longer editable from the product
                                    form. Use "Adjust stock" so changes go through the inventory
                                    engine and push to WooCommerce. */}
                                {data.type === 'simple' && (
                                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4 pt-2 items-end">
                                        <div>
                                            <label className="block text-xs font-medium text-content-muted mb-1">Stock on hand</label>
                                            <div className="px-3 py-2 rounded-lg bg-surface border border-line text-content text-sm font-semibold tabular-nums">
                                                {product?.total_stock ?? 0}
                                            </div>
                                        </div>
                                        {canAdjustStock && (
                                            <button
                                                type="button"
                                                onClick={() => setAdjusting({ variantId: null, variantName: null })}
                                                className="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-medium rounded-lg bg-surface-3 border border-line text-content hover:bg-content/10 h-[38px]"
                                            >
                                                <SlidersHorizontal className="w-3.5 h-3.5" /> Adjust stock
                                            </button>
                                        )}
                                        <p className="md:col-span-3 text-[11px] text-content-muted">Stock is managed through inventory adjustments.</p>
                                    </div>
                                )}
                            </Section>

                            {/* Options Section */}
                            {data.type === 'variable' && (
                                <Section title="Product options">
                                    <div className="bg-surface p-4 rounded-xl border border-line space-y-4">

                                        {/* Add a new option */}
                                        <div className="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
                                            <div>
                                                <label className="block text-xs text-content-muted mb-1">Option name</label>
                                                <input type="text" placeholder="e.g., Size" value={currentAttrName} onChange={e => setCurrentAttrName(e.target.value)}
                                                    className="w-full px-3 py-1.5 text-xs rounded-lg bg-surface-2 border border-line text-content focus:outline-none" />
                                            </div>
                                            <div>
                                                <label className="block text-xs text-content-muted mb-1">Values (separate with ",")</label>
                                                <input type="text" placeholder="e.g., S, M, L" value={currentAttrValues} onChange={e => setCurrentAttrValues(e.target.value)}
                                                    className="w-full px-3 py-1.5 text-xs rounded-lg bg-surface-2 border border-line text-content focus:outline-none" />
                                            </div>
                                            <button type="button" onClick={handleAddAttribute}
                                                className="px-3 py-1.5 text-xs font-medium rounded-lg bg-surface-3 border border-line text-content hover:bg-content/10 flex items-center justify-center gap-1 h-[32px]">
                                                <Plus className="w-3.5 h-3.5" /> Add option
                                            </button>
                                        </div>

                                        {/* Existing options — rename, add/remove values, delete option */}
                                        <div className="space-y-2">
                                            {data.options && data.options.map((option, idx) => (
                                                <OptionRow
                                                    key={idx}
                                                    option={option}
                                                    onRename={(name) => handleRenameOption(idx, name)}
                                                    onAddValue={(value) => handleAddOptionValue(idx, value)}
                                                    onRemoveValue={(vIdx) => handleRemoveOptionValue(idx, vIdx)}
                                                    onRemoveOption={() => handleRemoveAttribute(idx)}
                                                />
                                            ))}
                                        </div>

                                        <button type="button" onClick={generateVariantsMatrix}
                                            className="w-full bg-indigo-600/20 hover:bg-indigo-600/30 text-indigo-600 dark:text-indigo-400 border border-indigo-500/30 py-2 rounded-lg text-xs font-semibold tracking-wide transition flex items-center justify-center gap-1">
                                            <Layers className="w-3.5 h-3.5" /> Generate variants
                                        </button>

                                        <PublishReadinessNotice options={data.options} variants={data.variants} />
                                    </div>
                                </Section>
                            )}

                            {/* Variants Section */}
                            {data.type === 'variable' && (
                                <Section title="Variants">
                                    <div className="bg-surface p-4 rounded-xl border border-line space-y-4">
                                        {data.variants && data.variants.length > 0 && (
                                            <div className="flex justify-end">
                                                <button type="button" onClick={handleRegenerateSkus}
                                                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-surface-3 border border-line text-content hover:bg-content/10">
                                                    <Layers className="w-3.5 h-3.5" /> Regenerate SKUs
                                                </button>
                                            </div>
                                        )}
                                        {data.variants && data.variants.length > 0 ? (
                                            <div className="overflow-x-auto border border-line rounded-lg mt-2">
                                                <table className="w-full text-left border-collapse bg-surface text-xs">
                                                    <thead className="bg-surface text-content-muted font-medium border-b border-line">
                                                        <tr>
                                                            <th className="p-2.5">Combination</th>
                                                            <th className="p-2.5">Variant SKU</th>
                                                            <th className="p-2.5">Price (MAD)</th>
                                                            <th className="p-2.5">Stock Quantity (الكمية)</th>
                                                            <th className="p-2.5">Listings</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody className="divide-y divide-line">
                                                        {data.variants.map((v, index) => (
                                                            <tr key={index} className="hover:bg-surface-2/30">
                                                                <td className="p-2.5 font-medium text-content-muted whitespace-nowrap">
                                                                    {/* Display-only string — the real identity is the
                                                                        options map/canonical ids, never this text. */}
                                                                    {Object.entries(v.options ?? {}).map(([k, val]) => `${k}: ${val}`).join(' / ') || '—'}
                                                                </td>
                                                                <td className="p-2.5">
                                                                    <input type="text" value={v.sku || ''} onChange={e => handleVariantChange(index, 'sku', e.target.value)}
                                                                        className="bg-surface-2 border border-line rounded px-2 py-1 text-xs text-content w-full focus:outline-none focus:border-indigo-500" />
                                                                </td>
                                                                <td className="p-2.5">
                                                                    <input type="number" step="0.01" value={v.price ?? ''} onChange={e => handleVariantChange(index, 'price', e.target.value)}
                                                                        className="bg-surface-2 border border-line rounded px-2 py-1 text-xs text-content w-24 focus:outline-none focus:border-indigo-500" />
                                                                </td>
                                                                <td className="p-2.5">
                                                                    <div className="flex items-center gap-2">
                                                                        <span className="text-emerald-600 dark:text-emerald-400 font-bold tabular-nums">{stockForVariant(v.id)}</span>
                                                                        {canAdjustStock && (
                                                                            v.id ? (
                                                                                <button
                                                                                    type="button"
                                                                                    onClick={() => setAdjusting({ variantId: v.id, variantName: Object.entries(v.options ?? {}).map(([k, val]) => `${k}: ${val}`).join(' / ') })}
                                                                                    className="text-content-muted hover:text-content"
                                                                                    title="Adjust stock"
                                                                                >
                                                                                    <SlidersHorizontal className="w-3.5 h-3.5" />
                                                                                </button>
                                                                            ) : (
                                                                                <span className="text-[10px] text-content-muted italic">Save product first to adjust stock.</span>
                                                                            )
                                                                        )}
                                                                    </div>
                                                                </td>
                                                                <td className="p-2.5">
                                                                    {(v.channel_listings ?? []).length === 0 ? (
                                                                        <span className="text-content-muted/60">—</span>
                                                                    ) : (
                                                                        <div className="flex flex-wrap gap-1">
                                                                            {v.channel_listings.map((l) => (
                                                                                <span key={l.id} className="px-1.5 py-0.5 rounded bg-surface-3 border border-line text-[10px] text-content-muted">
                                                                                    {l.connection?.platform ?? 'platform'}
                                                                                </span>
                                                                            ))}
                                                                        </div>
                                                                    )}
                                                                </td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        ) : (
                                            <p className="text-xs text-amber-600 dark:text-amber-400/80 p-2 text-center bg-amber-500/5 rounded border border-amber-500/10">No variant items linked inside database array node yet.</p>
                                        )}
                                    </div>
                                </Section>
                            )}
                        </div>
                    )}

                    {/* STEP 4: STATUS FLOW & MEDIA ATTACHMENTS */}
                    {step === 4 && (
                        <div className="space-y-4 animate-fadeIn">
                            <Section title="Media Artifacts & Product Status">
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label className="block text-sm font-medium text-content-muted mb-1">Status</label>
                                        <select
                                            value={data.status} onChange={(e) => setData('status', e.target.value)}
                                            className="w-full px-3 py-2 rounded-lg bg-surface-3 border border-line text-content focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm"
                                        >
                                            <option value="active">Active</option>
                                            <option value="draft">Draft</option>
                                            <option value="archived">Archived</option>
                                        </select>
                                    </div>
                                    <Field label="Image URL" value={data.featured_image} onChange={(v) => setData('featured_image', v)} error={errors.featured_image} />
                                </div>
                            </Section>

                            <Section title="Channel mappings & inventory link">
                                <Card className="!p-4">
                                    <p className="text-xs text-content-muted mb-3">
                                        Managed by integrations/inventory engine — read-only here. Use "Publish to platforms" above to push changes out.
                                    </p>

                                    <div className="space-y-1.5">
                                        <p className="text-xs font-semibold text-content-muted uppercase tracking-wide">Product listings</p>
                                        {(product.channel_listings ?? []).length === 0 ? (
                                            <p className="text-xs text-content-muted">Not yet listed on any platform.</p>
                                        ) : (
                                            <div className="flex flex-wrap gap-2">
                                                {product.channel_listings.map((listing) => (
                                                    <div key={listing.id} className="flex items-center gap-2 px-2.5 py-1.5 rounded-lg bg-surface-3 border border-line text-xs">
                                                        <span className="font-medium text-content">{listing.connection?.label ?? listing.connection?.platform}</span>
                                                        <span className="text-content-muted font-mono">#{listing.external_product_id}</span>
                                                        <StatusBadge type="sync" status={listing.sync_status} />
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </div>

                                    <div className="mt-3 pt-3 border-t border-line space-y-1.5">
                                        <p className="text-xs font-semibold text-content-muted uppercase tracking-wide">Inventory / master SKU link</p>
                                        {product.inventory_link?.inventory_item ? (
                                            <p className="text-xs text-content">
                                                Linked to master SKU <span className="font-mono text-content-muted">{product.inventory_link.inventory_item.sku}</span>
                                                {' '}({product.inventory_link.inventory_item.name})
                                            </p>
                                        ) : (
                                            <p className="text-xs text-content-muted">No inventory item linked yet — created automatically the first time stock is recorded.</p>
                                        )}
                                    </div>
                                </Card>
                            </Section>
                        </div>
                    )}

                    {/* --- FOOTER FLOW CONTROLS --- */}
                    <div className="border-t border-line pt-4 flex justify-between items-center">
                        <button 
                            type="button" 
                            onClick={prevStep} 
                            disabled={step === 1}
                            className={`px-4 py-2 text-xs font-medium text-content-muted bg-surface-3 rounded-lg hover:bg-content/10 transition ${
                                step === 1 ? 'opacity-30 cursor-not-allowed' : ''
                            }`}
                        >
                            Previous
                        </button>

                        {step < maxSteps ? (
                            <button 
                                type="button" 
                                onClick={nextStep} 
                                className="px-4 py-2 text-xs font-semibold text-content bg-indigo-600 rounded-lg hover:bg-indigo-500 transition"
                            >
                                Next Step
                            </button>
                        ) : (
                            <button 
                                type="submit" 
                                disabled={processing} 
                                className="inline-flex items-center gap-1.5 px-5 py-2 text-xs font-bold rounded-lg bg-emerald-600 text-white hover:bg-emerald-500 disabled:opacity-50 transition shadow-md"
                            >
                                {processing ? (
                                    <>
                                        <Loader2 className="w-4 h-4 animate-spin" /> Saving changes…
                                    </>
                                ) : (
                                    <>
                                        <Save className="w-4 h-4" /> Save Product & Variants
                                    </>
                                )}
                            </button>
                        )}
                    </div>

                </form>
            </div>

            {adjusting && (
                <AdjustStockModal
                    product={product}
                    variantId={adjusting.variantId}
                    variantName={adjusting.variantName}
                    warehouses={warehouses}
                    onClose={() => setAdjusting(null)}
                    onAdjusted={() => router.reload({ only: ['product'] })}
                />
            )}

            {publishModalOpen && (
                <PublishTargetModal
                    mode="single"
                    product={product}
                    connections={connections}
                    readiness={readiness}
                    onClose={() => setPublishModalOpen(false)}
                    onPublished={() => router.reload({ only: ['product'] })}
                />
            )}
        </SaasLayout>
    );
}

function Section({ title, children }) {
    return (
        <div className="space-y-3">
            <h3 className="text-xs font-bold uppercase tracking-wider text-indigo-600 dark:text-indigo-400 border-b border-line/60 pb-1.5">{title}</h3>
            {children}
        </div>
    );
}

function Field({ label, type = 'text', value, onChange, error, required, ...rest }) {
    return (
        <div className="w-full">
            <label className="block text-xs font-medium text-content-muted mb-1">{label} {required && <span className="text-red-600 dark:text-red-400">*</span>}</label>
            <input type={type} value={value} onChange={(e) => onChange(e.target.value)} {...rest}
                className={`w-full px-3 py-2 rounded-lg bg-surface-3 border ${error ? 'border-red-500' : 'border-line'} text-content text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500`} />
            {error && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{error}</p>}
        </div>
    );
}

function TextArea({ label, value, onChange, error }) {
    return (
        <div className="w-full">
            <label className="block text-xs font-medium text-content-muted mb-1">{label}</label>
            <textarea rows={3} value={value} onChange={(e) => onChange(e.target.value)}
                className={`w-full px-3 py-2 rounded-lg bg-surface-3 border ${error ? 'border-red-500' : 'border-line'} text-content text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500`} />
            {error && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{error}</p>}
        </div>
    );
}

/** One option row: rename inline, add/remove values, delete the option. */
export function OptionRow({ option, onRename, onAddValue, onRemoveValue, onRemoveOption }) {
    const [editingName, setEditingName] = useState(false);
    const [nameDraft, setNameDraft] = useState(option.name);
    const [newValue, setNewValue] = useState('');

    const commitName = () => {
        setEditingName(false);
        if (nameDraft.trim() !== '' && nameDraft.trim() !== option.name) onRename(nameDraft.trim());
        else setNameDraft(option.name);
    };

    return (
        <div className="bg-surface-2 px-3 py-2 rounded-lg border border-line space-y-2">
            <div className="flex items-center justify-between gap-2">
                {editingName ? (
                    <input
                        autoFocus
                        value={nameDraft}
                        onChange={(e) => setNameDraft(e.target.value)}
                        onBlur={commitName}
                        onKeyDown={(e) => e.key === 'Enter' && commitName()}
                        className="text-xs font-semibold uppercase bg-surface border border-indigo-500/40 rounded px-1.5 py-0.5 text-content focus:outline-none"
                    />
                ) : (
                    <button type="button" onClick={() => setEditingName(true)} className="text-xs font-semibold text-indigo-600 dark:text-indigo-400 uppercase hover:underline">
                        {option.name}:
                    </button>
                )}
                <button type="button" onClick={onRemoveOption} className="text-content-muted hover:text-red-600 dark:text-red-400 transition">
                    <Trash2 className="w-4 h-4" />
                </button>
            </div>

            <div className="flex flex-wrap items-center gap-1.5">
                {option.values.map((v, vIdx) => (
                    <span key={vIdx} className="inline-flex items-center gap-1 bg-surface px-2 py-0.5 rounded border border-line text-content-muted text-xs">
                        {v}
                        <button type="button" onClick={() => onRemoveValue(vIdx)} className="text-content-muted/60 hover:text-red-600 dark:hover:text-red-400">
                            <Trash2 className="w-3 h-3" />
                        </button>
                    </span>
                ))}
                <input
                    value={newValue}
                    onChange={(e) => setNewValue(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') { e.preventDefault(); onAddValue(newValue); setNewValue(''); }
                    }}
                    onBlur={() => { if (newValue.trim() !== '') { onAddValue(newValue); setNewValue(''); } }}
                    placeholder="+ value"
                    className="w-20 text-xs bg-surface border border-dashed border-line rounded px-1.5 py-0.5 text-content focus:outline-none focus:border-indigo-500"
                />
            </div>
        </div>
    );
}

/**
 * Server-computed readiness for the product as currently SAVED (ProductPublishReadinessService)
 * — the same check that gates the publish endpoints. Informational only; it
 * never blocks saving this form. Distinct from PublishReadinessNotice below,
 * which previews unsaved form state client-side.
 */
function PublishReadinessPanel({ readiness = {} }) {
    const platforms = Object.entries(readiness);
    if (platforms.length === 0) return null;

    const badge = { ready: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300', warning: 'bg-amber-500/15 text-amber-700 dark:text-amber-300', blocked: 'bg-red-500/15 text-red-700 dark:text-red-300' };

    return (
        <div className="bg-surface-2 border border-line rounded-xl p-4">
            <p className="text-xs font-semibold text-content-muted uppercase tracking-wide mb-2.5">Publish readiness</p>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-2.5">
                {platforms.map(([platform, report]) => (
                    <div key={platform} className="rounded-lg border border-line bg-surface p-3">
                        <div className="flex items-center gap-2">
                            <span className="text-sm font-medium text-content uppercase">{platform}</span>
                            <span className={`text-[10px] px-1.5 py-0.5 rounded font-semibold uppercase ${badge[report.status] ?? 'bg-slate-500/15 text-content-muted'}`}>
                                {report.status}
                            </span>
                        </div>
                        {[...(report.errors ?? []), ...(report.warnings ?? [])].slice(0, 2).map((msg, i) => (
                            <p key={i} className="mt-1 text-xs text-content-muted">{msg}</p>
                        ))}
                    </div>
                ))}
            </div>
        </div>
    );
}

/**
 * Read-only, client-side-only heads-up — not a publish gate. Mirrors the
 * real constraints Shopify/WooCommerce enforce so a user finds out before
 * clicking Publish, not from a failed push.
 */
export function PublishReadinessNotice({ options = [], variants = [] }) {
    const warnings = [];

    if (options.length > 3) {
        warnings.push('Shopify allows a maximum of 3 options per product — this product has ' + options.length + '.');
    }

    const optionNames = options.map((o) => o.name);
    const incompleteVariant = variants.some((v) => optionNames.some((name) => !v.options?.[name]));
    if (variants.length > 0 && incompleteVariant) {
        warnings.push('Shopify requires every variant to have a value for every option — regenerate variants after editing options.');
    }

    if (options.length === 0 || variants.length === 0) {
        warnings.push('WooCommerce needs at least one attribute and one variation for a variable product.');
    }

    if (warnings.length === 0) return null;

    return (
        <div className="rounded-lg border border-amber-500/30 bg-amber-500/5 p-3 space-y-1">
            <p className="text-[11px] font-semibold text-amber-700 dark:text-amber-300 uppercase tracking-wide">Publish readiness</p>
            {warnings.map((w, i) => (
                <p key={i} className="text-xs text-amber-700 dark:text-amber-300">{w}</p>
            ))}
        </div>
    );
}