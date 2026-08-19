import { useState, useEffect } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Loader2, Save, Trash2, Plus, Layers, Package, Settings, CheckCircle, Upload } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import Card from '@/Components/Card';
import StatusBadge from '@/Components/StatusBadge';

export default function Edit({ product, connections = [] }) {
    
    // 1. دالة بناء الـ Attributes من الـ Variants كيفما كان كيدير الـ Livewire بالظبط
    const buildAttributesFromProduct = (variants) => {
        if (!variants || !Array.isArray(variants)) return [];
        const attrsMap = {};
        
        variants.forEach(variant => {
            const attrs = variant.attributes || {}; 
            Object.entries(attrs).forEach(([name, value]) => {
                if (!attrsMap[name]) {
                    attrsMap[name] = { name: name, values: [] };
                }
                if (value !== null && value !== '' && !attrsMap[name].values.includes(String(value))) {
                    attrsMap[name].values.push(String(value));
                }
            });
        });
        return Object.values(attrsMap);
    };

    // 2. دالة تجهيز الـ Variants بنفس الـ Structure ديال الـ Livewire (مع حقل qty الموحد)
    const prepareVariantsFromProduct = (variants) => {
        if (!variants || !Array.isArray(variants)) return [];
        return variants.map(variant => {
            let stockQty = 0;
            if (variant.stocks && Array.isArray(variant.stocks)) {
                stockQty = variant.stocks.reduce((sum, s) => sum + (parseInt(s.quantity) || 0), 0);
            } else {
                stockQty = parseInt(variant.qty) || parseInt(variant.stock) || 0;
            }

            return {
                id: variant.id || null,
                combo_key: variant.combo_key || Object.values(variant.attributes || {}).join('|'),
                name: variant.name || Object.values(variant.attributes || {}).join(' / '),
                attributes: variant.attributes || {}, 
                sku: variant.sku ?? '',
                price: variant.price ? String(variant.price) : '',
                cost: variant.cost ? String(variant.cost) : '',
                qty: stockQty,
                selected: true,
                channel_listings: variant.channel_listings ?? [],
            };
        });
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
        
        // ✨ استقبال كمية المخزن الإجمالية للـ Simple Product اللي جاية من الـ Controller
        qty: product?.total_stock ?? 0, 
        
        productAttributes: buildAttributesFromProduct(product?.variants), 
        variants: prepareVariantsFromProduct(product?.variants),     
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
                
                // ✨ الـ Sync ديال كونتيتي الـ Simple Product من الداتابيز
                qty: product.total_stock ?? 0, 
                
                productAttributes: buildAttributesFromProduct(product.variants),
                variants: prepareVariantsFromProduct(product.variants)
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
        if (data.productAttributes.some(a => a.name.toLowerCase() === currentAttrName.trim().toLowerCase())) return;

        const newAttribute = {
            name: currentAttrName.trim(),
            values: valuesArray
        };

        setData('productAttributes', [...data.productAttributes, newAttribute]);
        setCurrentAttrName('');
        setCurrentAttrValues('');
    };

    const handleRemoveAttribute = (indexToRemove) => {
        const updatedAttrs = data.productAttributes.filter((_, index) => index !== indexToRemove);
        setData('productAttributes', updatedAttrs);
    };

    const generateVariantsMatrix = () => {
        if (data.productAttributes.length === 0) return;

        const cartesian = (sets) => sets.reduce((acc, set) => acc.flatMap(x => set.map(y => [...x, y])), [[]]);
        const sets = data.productAttributes.map(attr => attr.values.map(val => ({ [attr.name]: val })));
        
        if (sets.some(s => s.length === 0)) {
            alert('المرجو إدخال قيم لكل خاصية أولاً');
            return;
        }

        const combinations = cartesian(sets);

        const generatedVariants = combinations.map(combo => {
            const options = Object.assign({}, ...combo); 
            const comboKey = Object.values(options).join('|');
            const skuSuffix = Object.values(options).join('-').toUpperCase();
            const variantSku = data.sku ? `${data.sku}-${skuSuffix}` : skuSuffix;

            const existingVariant = data.variants.find(v => v.combo_key === comboKey);

            return existingVariant ?? {
                id: null,
                combo_key: comboKey,
                name: Object.values(options).join(' / '),
                attributes: options,
                sku: variantSku,
                price: data.price || '',
                cost: data.cost || '',
                qty: 0,
                selected: true
            };
        });

        setData('variants', generatedVariants);
    };

    const handleVariantChange = (index, field, value) => {
        const updatedVariants = [...data.variants];
        if (field === 'qty') {
            updatedVariants[index]['qty'] = parseInt(value) || 0;
        } else {
            updatedVariants[index][field] = value;
        }
        setData('variants', updatedVariants);
    };

    const submit = (e) => {
        e.preventDefault();
        patch(`/dashboard/products/${product.id}`);
    };

    const nextStep = () => step < maxSteps && setStep(step + 1);
    const prevStep = () => step > 1 && setStep(step - 1);

    // Publishing is a separate, immediate action against the already-saved
    // product — reuses the same ProductPushService the old wizard called.
    const [publishing, setPublishing] = useState(false);
    const publish = () => {
        setPublishing(true);
        router.post(`/dashboard/products/${product.id}/push`, {}, {
            preserveScroll: true,
            onFinish: () => setPublishing(false),
        });
    };

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
                        onClick={publish}
                        disabled={publishing || connections.length === 0}
                        title={connections.length === 0 ? 'No active platform connections for this store.' : undefined}
                        className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed transition"
                    >
                        {publishing ? <Loader2 className="w-4 h-4 animate-spin" /> : <Upload className="w-4 h-4" />}
                        Publish to platforms
                    </button>
                    <Link href="/dashboard/products" className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface-3 border border-line text-content-muted hover:bg-content/10 hover:text-content transition">
                        <ArrowLeft className="w-4 h-4" /> Back
                    </Link>
                </div>
            ),
        }}>
            
            <div className="max-w-4xl mx-auto space-y-6">
                
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

                                {/* ✨ إظهار حقل الـ Stock الكونتيتي هنايا إذا كان نوع المنتج Simple */}
                                {data.type === 'simple' && (
                                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4 pt-2">
                                        <Field 
                                            label="Stock Quantity (الكمية الإجمالية)" 
                                            type="number" 
                                            value={data.qty} 
                                            onChange={(v) => setData('qty', parseInt(v) || 0)} 
                                            error={errors.qty} 
                                            required 
                                        />
                                    </div>
                                )}
                            </Section>

                            {/* Variable Mapping Section */}
                            {data.type === 'variable' && (
                                <Section title="Synced Attributes & Variants Mapping Matrix">
                                    <div className="bg-surface p-4 rounded-xl border border-line space-y-4">
                                        
                                        {/* خيارات إضافة خصائص جديدة */}
                                        <div className="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
                                            <div>
                                                <label className="block text-xs text-content-muted mb-1">Attribute Name</label>
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
                                                <Plus className="w-3.5 h-3.5" /> Add Attribute
                                            </button>
                                        </div>

                                        {/* الخصائص الحالية للمنتج */}
                                        <div className="space-y-2">
                                            {data.productAttributes && data.productAttributes.map((attr, idx) => (
                                                <div key={idx} className="flex items-center justify-between bg-surface-2 px-3 py-2 rounded-lg border border-line">
                                                    <div className="flex items-center gap-4 text-xs">
                                                        <span className="font-semibold text-indigo-600 dark:text-indigo-400 uppercase">{attr.name}:</span>
                                                        <div className="flex flex-wrap gap-1">
                                                            {attr.values && attr.values.map((v, vIdx) => (
                                                                <span key={vIdx} className="bg-surface px-2 py-0.5 rounded border border-line text-content-muted">{v}</span>
                                                            ))}
                                                        </div>
                                                    </div>
                                                    <button type="button" onClick={() => handleRemoveAttribute(idx)} className="text-content-muted hover:text-red-600 dark:text-red-400 transition">
                                                        <Trash2 className="w-4 h-4" />
                                                    </button>
                                                </div>
                                            ))}
                                        </div>

                                        <button type="button" onClick={generateVariantsMatrix}
                                            className="w-full bg-indigo-600/20 hover:bg-indigo-600/30 text-indigo-600 dark:text-indigo-400 border border-indigo-500/30 py-2 rounded-lg text-xs font-semibold tracking-wide transition flex items-center justify-center gap-1">
                                            <Layers className="w-3.5 h-3.5" /> Re-generate / Sync Matrix Permutations
                                        </button>

                                        {/* جدول الـ Variants والـ qty */}
                                        {data.variants && data.variants.length > 0 ? (
                                            <div className="overflow-x-auto border border-line rounded-lg mt-2">
                                                <table className="w-full text-left border-collapse bg-surface text-xs">
                                                    <thead className="bg-surface text-content-muted font-medium border-b border-line">
                                                        <tr>
                                                            <th className="p-2.5">Combination Options</th>
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
                                                                    {v.name || '—'}
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
                                                                    <input type="number" value={v.qty ?? 0} onChange={e => handleVariantChange(index, 'qty', e.target.value)}
                                                                        className="bg-surface-2 border border-indigo-500/30 rounded px-2 py-1 text-xs text-emerald-600 dark:text-emerald-400 w-24 focus:outline-none focus:border-indigo-500 font-bold" />
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