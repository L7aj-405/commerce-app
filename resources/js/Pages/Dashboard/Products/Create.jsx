import { useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Loader2, Save, Plus, Trash2, Layers } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';

export default function Create() {
    // 1. تحديث الـ Form State لتشمل الحقول الجديدة الخاصة بالـ Variants والباكيند
    const { data, setData, post, processing, errors } = useForm({
        name: '', 
        sku: '', 
        description: '', 
        category: '',
        price: 0, 
        compare_price: '', 
        cost: '',
        featured_image: '',
        
        // 🆕 الحقول الجديدة لي غاتمشي للباكيند
        type: 'simple',        // simple أو variable
        attributes: [],        // لتخزين الـ [{ name: 'Size', values: ['S', 'M'] }]
        variants: [],          // لتخزين المصفوفة النهائية للـ [ {sku, price, stock, options} ]
    });

    // ستايتس محلية خفيفة لإدخال الـ Attributes بسرعة ف الـ UI
    const [currentAttrName, setCurrentAttrName] = useState('');
    const [currentAttrValues, setCurrentAttrValues] = useState('');

    // ============================================
    // الـ LOGIC الخاصة بتوليد الـ VARIANTS MATRIX
    // ============================================

    // إضافة الخاصية وقيمها (مثال: الخاصية Size وقيمها S, M, L دفعة واحدة)
    const handleAddAttribute = (e) => {
        e.preventDefault();
        if (!currentAttrName.trim() || !currentAttrValues.trim()) return;

        // تقسيم القيم بالفاصلة وتنظيف الفراغات
        const valuesArray = currentAttrValues.split(',').map(v => v.trim()).filter(v => v !== '');

        const newAttribute = {
            name: currentAttrName.trim(),
            values: valuesArray
        };

        setData('attributes', [...data.attributes, newAttribute]);
        setCurrentAttrName('');
        setCurrentAttrValues('');
    };

    // حذف خاصية كاملة
    const handleRemoveAttribute = (indexToRemove) => {
        const updatedAttrs = data.attributes.filter((_, index) => index !== indexToRemove);
        setData('attributes', updatedAttrs);
    };

    // دالة الـ Cartesian Product السحرية لتوليد كاع الاحتمالات بلحظتها ف المتصفح
    const generateVariantsMatrix = () => {
        if (data.attributes.length === 0) return;

        const cartesian = (sets) => sets.reduce((acc, set) => acc.flatMap(x => set.map(y => [...x, y])), [[]]);
        const sets = data.attributes.map(attr => attr.values.map(val => ({ [attr.name]: val })));
        
        if (sets.some(s => s.length === 0)) {
            alert('المرجو إدخال قيم لكل خاصية أولاً');
            return;
        }

        const combinations = cartesian(sets);

        // تحويل الاحتمالات إلى الـ Structure لي كيتسناه الباكيند ديالك
        const generatedVariants = combinations.map(combo => {
            const options = Object.assign({}, ...combo); // كترجع بحال: { Size: 'S', Color: 'Black' }
            
            // توليد SKU تلقائي ذكي مبني على الـ SKU الرئيسي والخصائص المدخلة
            const skuSuffix = Object.values(options).join('-').toUpperCase();
            const variantSku = data.sku ? `${data.sku}-${skuSuffix}` : skuSuffix;

            return {
                sku: variantSku,
                price: data.price || 0, // كياخد الثمن الرئيسي كبداية تسهيلاً للكليان
                stock: 0,
                options: options // هادا هو الـ JSON column لي غايتحفظ ف الـ variants table
            };
        });

        setData('variants', generatedVariants);
    };

    // تحديث حقل معين داخل فاريانت محدد وسط جدول الـ Matrix
    const handleVariantChange = (index, field, value) => {
        const updatedVariants = [...data.variants];
        updatedVariants[index][field] = value;
        setData('variants', updatedVariants);
    };

    const submit = (e) => { 
        e.preventDefault(); 
        post('/dashboard/products'); 
    };

    return (
        <SaasLayout pageHeader={{
            title: 'Add product',
            breadcrumbs: [
                { label: 'Dashboard', href: '/dashboard' },
                { label: 'Products',  href: '/dashboard/products' },
                { label: 'Add' },
            ],
            actions: (
                <Link href="/dashboard/products" className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface-2 border border-line text-content-muted hover:bg-surface-3 hover:text-content">
                    <ArrowLeft className="w-4 h-4" /> Back
                </Link>
            ),
        }}>
            <form onSubmit={submit} className="bg-surface-2 border border-line rounded-xl p-6 max-w-4xl space-y-6">
                
                {/* القسم 1: المعلومات الأساسية ونوع المنتج */}
                <Section title="Basic information">
                    <Field label="Name" value={data.name} onChange={(v) => setData('name', v)} error={errors.name} required />
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <Field label="SKU" value={data.sku} onChange={(v) => setData('sku', v)} error={errors.sku} required />
                        <Field label="Category" value={data.category} onChange={(v) => setData('category', v)} error={errors.category} />
                    </div>
                    <TextArea label="Description" value={data.description} onChange={(v) => setData('description', v)} error={errors.description} />
                    
                    {/* 🆕 حقل اختيار نوع المنتج لتبديل الواجهة */}
                    <div className="pt-2">
                        <label className="block text-sm font-medium text-content-muted mb-1">Product Type</label>
                        <select 
                            value={data.type} 
                            onChange={(e) => setData('type', e.target.value)}
                            className="w-full px-3 py-2 rounded-lg bg-surface-3 border border-line text-content focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm"
                        >
                            <option value="simple">Simple Product (منتج عادي)</option>
                            <option value="variable">Variable Product (منتج بخصائص وفاريانتس)</option>
                        </select>
                    </div>
                </Section>

                {/* القسم 2: التسعير */}
                <Section title="Pricing">
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <Field label="Price" type="number" step="0.01" value={data.price} onChange={(v) => setData('price', v)} error={errors.price} required />
                        <Field label="Compare price" type="number" step="0.01" value={data.compare_price} onChange={(v) => setData('compare_price', v)} error={errors.compare_price} />
                        <Field label="Cost" type="number" step="0.01" value={data.cost} onChange={(v) => setData('cost', v)} error={errors.cost} />
                    </div>
                </Section>

                {/* القسم 3: إدارة الـ Attributes والـ Variants (يظهر فقط إذا كان المنتج Variable) */}
                {data.type === 'variable' && (
                    <Section title="Attributes & Variants Mapping">
                        <div className="bg-surface p-4 rounded-xl border border-line space-y-4">
                            
                            {/* حقول إضافة الخصائص دغيا دغيا */}
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

                            {/* عرض الـ Attributes المضافة حالياً مع إمكانية الحذف */}
                            <div className="space-y-2">
                                {data.attributes.map((attr, idx) => (
                                    <div key={idx} className="flex items-center justify-between bg-surface-2 px-3 py-2 rounded-lg border border-line">
                                        <div className="flex items-center gap-4 text-xs">
                                            <span className="font-semibold text-indigo-600 dark:text-indigo-400 uppercase">{attr.name}:</span>
                                            <div className="flex flex-wrap gap-1">
                                                {attr.values.map((v, vIdx) => (
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

                            {data.attributes.length > 0 && (
                                <button type="button" onClick={generateVariantsMatrix}
                                    className="w-full bg-indigo-600/20 hover:bg-indigo-600/30 text-indigo-600 dark:text-indigo-400 border border-indigo-500/30 py-2 rounded-lg text-xs font-semibold tracking-wide transition flex items-center justify-center gap-1">
                                    <Layers className="w-3.5 h-3.5" /> Generate Variants Matrix
                                </button>
                            )}

                            {/* الجدول الديناميكي لملء قيم كل فاريانت على حدة */}
                            {data.variants.length > 0 && (
                                <div className="overflow-x-auto border border-line rounded-lg mt-2">
                                    <table className="w-full text-left border-collapse bg-surface text-xs">
                                        <thead className="bg-surface text-content-muted font-medium border-b border-line">
                                            <tr>
                                                <th className="p-2.5">Combination</th>
                                                <th className="p-2.5">Variant SKU</th>
                                                <th className="p-2.5">Price (MAD)</th>
                                                <th className="p-2.5">Initial Stock</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-line">
                                            {data.variants.map((v, index) => (
                                                <tr key={index} className="hover:bg-surface-2/30">
                                                    <td className="p-2.5 font-medium text-content-muted whitespace-nowrap">
                                                        {Object.entries(v.options).map(([k, val]) => `${k}: ${val}`).join(' / ')}
                                                    </td>
                                                    <td className="p-2.5">
                                                        <input type="text" value={v.sku} onChange={e => handleVariantChange(index, 'sku', e.target.value)}
                                                            className="bg-surface-2 border border-line rounded px-2 py-1 text-xs text-content w-full focus:outline-none focus:border-indigo-500" />
                                                    </td>
                                                    <td className="p-2.5">
                                                        <input type="number" step="0.01" value={v.price} onChange={e => handleVariantChange(index, 'price', e.target.value)}
                                                            className="bg-surface-2 border border-line rounded px-2 py-1 text-xs text-content w-24 focus:outline-none focus:border-indigo-500" />
                                                    </td>
                                                    <td className="p-2.5">
                                                        <input type="number" value={v.stock} onChange={e => handleVariantChange(index, 'stock', e.target.value)}
                                                            className="bg-surface-2 border border-line rounded px-2 py-1 text-xs text-content w-20 focus:outline-none focus:border-indigo-500" />
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    </Section>
                )}

                {/* القسم 4: الصورة */}
                <Section title="Image">
                    <Field label="Image URL" value={data.featured_image} onChange={(v) => setData('featured_image', v)} error={errors.featured_image} placeholder="https://…" />
                    {data.featured_image && (
                        <img src={data.featured_image} alt="" className="mt-2 w-32 h-32 rounded-lg object-cover ring-1 ring-line" />
                    )}
                </Section>

                {/* بوطونة الحفظ */}
                <button
                    type="submit"
                    disabled={processing}
                    className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 disabled:opacity-50 transition"
                >
                    {processing ? <><Loader2 className="w-4 h-4 animate-spin" /> Saving & Syncing…</> : <><Save className="w-4 h-4" /> Create product</>}
                </button>
            </form>
        </SaasLayout>
    );
}

// المكونات الداخلية الفرعية (حافظنا عليها كيف ما كانت عندك بالضبط)
function Section({ title, children }) {
    return (
        <div className="space-y-4">
            <h3 className="text-sm font-semibold text-content border-b border-line/50 pb-1">{title}</h3>
            {children}
        </div>
    );
}
function Field({ label, type = 'text', value, onChange, error, required, ...rest }) {
    return (
        <div>
            <label className="block text-sm font-medium text-content-muted mb-1">
                {label} {required && <span className="text-red-600 dark:text-red-400">*</span>}
            </label>
            <input 
                type={type} 
                value={value} 
                //  هنا خاصنا ناخدو e.target.value حيت هادا input عادي ديال الـ HTML
                onChange={(e) => onChange(e.target.value)} 
                {...rest}
                className={`w-full px-3 py-2 rounded-lg bg-surface-3 border ${error ? 'border-red-500' : 'border-line'} text-content text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500`} 
            />
            {error && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{error}</p>}
        </div>
    );
}
function TextArea({ label, value, onChange, error }) {
    return (
        <div>
            <label className="block text-sm font-medium text-content-muted mb-1">{label}</label>
            <textarea rows={3} value={value} onChange={(e) => onChange(e.target.value)}
                className={`w-full px-3 py-2 rounded-lg bg-surface-3 border ${error ? 'border-red-500' : 'border-line'} text-content text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500`} />
            {error && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{error}</p>}
        </div>
    );
}