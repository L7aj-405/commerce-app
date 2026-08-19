import { useEffect, useState } from 'react';

export default function InvoiceModal({ invoice, currency, settings = {}, onClose }) {
    
    const design = settings.invoice_design || {
        default_type: 'thermal',
        theme_color: '#4f46e5',
        logo_position: 'left',
        show_logo: true,
        show_sku: true,
        blocks: [
            { id: 'header', label: 'Header & Logo' },
            { id: 'billing_info', label: 'Billing Info' },
            { id: 'items_table', label: 'Products Table' },
            { id: 'footer_notes', label: 'Footer Notes' }
        ]
    };

    // Dynamic print trigger: companies get an A4 invoice, individuals a thermal
    // receipt. The manual toggle below still lets the cashier override per print.
    const [printFormat, setPrintFormat] = useState(
        invoice.customer_type === 'company' ? 'a4' : (design.default_type || 'thermal'),
    );

    // 🚀 دالة الطباعة السحرية وعزل الـ DOM ديريكت ف الـ body
    const handlePrint = () => {
        // 1. كنجيبو فقط الـ HTML الداخلي ديال منطقة الفاتورة
        const printContent = document.getElementById('invoice-to-print').innerHTML;
        
        // 2. كنكرييو div جديد مخصص فقط للطباعة ديريكت ف الـ body برا الـ React App
        const printWindow = document.createElement('div');
        printWindow.id = 'pure-print-area';
        printWindow.innerHTML = printContent;
        document.body.appendChild(printWindow);

        // 3. كنطلقو أمر الطباعة ف البلاصة
        window.print();

        // 4. ملي كيسالي الكيشير أو كيكنسلي، كنمسحو هاد الـ div باش ما يثقلش الصفحة
        document.body.removeChild(printWindow);
    };

    // تشغيل دالة الطبع التلقائي بمجرد فتح المودال أو تبديل الـ format
    useEffect(() => {
        const timer = setTimeout(() => {
            handlePrint();
        }, 500);
        return () => clearTimeout(timer);
    }, [printFormat, invoice]);

    return (
        <div className="fixed inset-0 bg-black/70 flex items-center justify-center z-50 no-print overflow-y-auto p-4">
            
            {/* 🎨 الـ CSS الخاص بالطباعة لي كيركز فقط على الـ pure-print-area الخارجي */}
            <style dangerouslySetInnerHTML={{__html: `
                @media print {
                    /* إخفاء الـ React App والمودال كلياً */
                    #app, #root, .no-print, .fixed {
                        display: none !important;
                    }
                    
                    /* إظهار فقط الـ div السحري لي كريينا ف الـ body */
                    #pure-print-area {
                        display: block !important;
                        position: absolute !important;
                        left: 0 !important;
                        top: 0 !important;
                        width: 100% !important;
                        background: white !important;
                        padding: ${printFormat === 'thermal' ? '0' : '15mm'} !important;
                        margin: 0 !important;
                    }

                    @page {
                        size: ${printFormat === 'thermal' ? '80mm auto' : 'A4'};
                        margin: 0mm !important;
                    }
                }
            `}} />

            <div className={`bg-white text-black p-6 rounded-lg shadow-2xl transition-all ${printFormat === 'a4' ? 'max-w-4xl w-full' : 'max-w-sm w-full'}`}>
                
                {/* 🛠️ أزرار التحكم السريع (كتخفى وقت الطبع) */}
                <div className="flex gap-2 mb-4 border-b pb-3 justify-between items-center">
                    <div className="flex gap-2">
                        <button 
                            type="button"
                            onClick={() => setPrintFormat('thermal')}
                            className={`px-3 py-1.5 text-xs rounded font-medium transition ${printFormat === 'thermal' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-800'}`}
                        >
                            Ticket (80mm)
                        </button>
                        <button 
                            type="button"
                            onClick={() => setPrintFormat('a4')}
                            className={`px-3 py-1.5 text-xs rounded font-medium transition ${printFormat === 'a4' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-800'}`}
                        >
                            Facture (A4)
                        </button>
                    </div>
                    <span className="text-[10px] text-gray-400 font-mono">Format: {printFormat.toUpperCase()}</span>
                </div>

                {/* 🖨️ منطقة كود الفاتورة المعزولة بـ ID */}
                <div id="invoice-to-print">
                    
                    {/* 1️⃣ الـ Thermal Format */}
                    {printFormat === 'thermal' && (
                        <div className="font-mono text-xs max-w-[80mm] mx-auto space-y-4 bg-white p-1">
                            <div className="text-center">
                                {design.show_logo && (
                                    <div className={`flex ${design.logo_position === 'center' ? 'justify-center' : design.logo_position === 'right' ? 'justify-end' : 'justify-start'} mb-1`}>
                                        <span className="text-xs border px-2 py-0.5" style={{ color: design.theme_color, borderColor: design.theme_color }}>[LOGO]</span>
                                    </div>
                                )}
                                <h2 className="text-base font-bold uppercase">Aniqa Store</h2>
                                <p className="text-[10px] text-gray-500">{settings.thermal_header_text || 'POS Receipt'}</p>
                            </div>
                            
                            <div className="border-b border-dashed border-gray-400"></div>

                            <div className="space-y-0.5 text-[11px]">
                                <div><strong>Receipt:</strong> #{invoice.receipt_number}</div>
                                <div><strong>Date:</strong> {new Date().toLocaleString('fr-FR')}</div>
                                <div><strong>Customer:</strong> {invoice.customer_name || 'Walk-in'}</div>
                                <div><strong>Payment:</strong> {invoice.payment_method?.toUpperCase()}</div>
                            </div>

                            <div className="border-b border-dashed border-gray-400"></div>

                            <table className="w-full text-[11px]">
                                <thead>
                                    <tr className="border-b border-gray-300 text-left">
                                        <th className="pb-1">Item</th>
                                        <th className="text-center pb-1">Qty</th>
                                        <th className="text-right pb-1">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {invoice.items.map((item, idx) => (
                                        <tr key={idx} className="align-top">
                                            <td className="py-1 max-w-[140px] truncate">
                                                {item.product_name}
                                                {design.show_sku && item.product_sku && <span className="block text-[9px] text-gray-500 font-sans">SKU: {item.product_sku}</span>}
                                            </td>
                                            <td className="text-center py-1">{item.quantity}</td>
                                            <td className="text-right py-1">{(item.unit_price * item.quantity).toFixed(2)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>

                            <div className="border-b border-dashed border-gray-400"></div>

                            <div className="space-y-1 text-right text-[11px]">
                                <div className="flex justify-between font-bold text-sm">
                                    <span>TOTAL:</span>
                                    <span>{currency} {Number(invoice.total_amount).toFixed(2)}</span>
                                </div>
                                <div className="flex justify-between text-gray-600">
                                    <span>Paid:</span>
                                    <span>{currency} {Number(invoice.amount_paid || 0).toFixed(2)}</span>
                                </div>
                                <div className="flex justify-between font-medium text-gray-700">
                                    <span>Change:</span>
                                    <span>{currency} {Number(invoice.change_amount || 0).toFixed(2)}</span>
                                </div>
                            </div>

                            <div className="border-b border-dashed border-gray-400 pt-2"></div>
                            <p className="text-center text-[10px] text-gray-400">{settings.thermal_footer_text || 'Thank you for your business!'}</p>
                        </div>
                    )}

                    {/* 2️⃣ الـ A4 Format */}
                    {printFormat === 'a4' && (
                        <div className="font-sans bg-white text-black w-full text-sm flex flex-col justify-between p-2">
                            <div className="space-y-8">
                                {design.blocks.map((block) => {
                                    if (block.id === 'header') {
                                        return (
                                            <div key={block.id} className="flex justify-between items-start w-full border-b pb-4">
                                                <div>
                                                    <h1 className="text-2xl font-bold uppercase tracking-wide" style={{ color: design.theme_color }}>FACTURE</h1>
                                                    <p className="text-xs text-gray-500">N°: {invoice.receipt_number}</p>
                                                    <p className="text-xs text-gray-500">Date: {new Date().toLocaleDateString('fr-FR')}</p>
                                                </div>
                                                {design.show_logo && (
                                                    <div className={`p-3 border-2 border-dashed rounded font-bold text-xs uppercase`} style={{ color: design.theme_color, borderColor: design.theme_color }}>
                                                        [ Brand Logo ]
                                                    </div>
                                                )}
                                            </div>
                                        );
                                    }

                                    if (block.id === 'billing_info') {
                                        return (
                                            <div key={block.id} className="grid grid-cols-2 gap-6 p-4 bg-gray-50 rounded-lg text-xs">
                                                <div>
                                                    <h3 className="font-bold border-b pb-1 mb-2 uppercase" style={{ color: design.theme_color, borderColor: design.theme_color }}>Émetteur</h3>
                                                    <p className="font-semibold text-sm">{settings.name || 'Company Name'}</p>
                                                    {settings.a4_company_ice && <p className="text-gray-600 mt-1">ICE: {settings.a4_company_ice}</p>}
                                                </div>
                                                <div>
                                                    <h3 className="font-bold border-b pb-1 mb-2 uppercase" style={{ color: design.theme_color, borderColor: design.theme_color }}>Client</h3>
                                                    <p className="font-semibold text-sm">{invoice.company_name || invoice.customer_name || 'Client Comptant'}</p>
                                                    {invoice.tax_id && <p className="text-gray-600 mt-1">ICE: {invoice.tax_id}</p>}
                                                    {invoice.company_name && invoice.customer_name && <p className="text-gray-500 mt-0.5">Contact: {invoice.customer_name}</p>}
                                                </div>
                                            </div>
                                        );
                                    }

                                    if (block.id === 'items_table') {
                                        return (
                                            <div key={block.id} className="space-y-4">
                                                <table className="w-full text-xs border-collapse">
                                                    <thead>
                                                        <tr className="text-white text-left" style={{ backgroundColor: design.theme_color }}>
                                                            <th className="p-2.5 rounded-l">Désignation</th>
                                                            {design.show_sku && <th className="p-2.5">SKU</th>}
                                                            <th className="p-2.5 text-center">P.U ({currency})</th>
                                                            <th className="p-2.5 text-center">Qté</th>
                                                            <th className="p-2.5 text-right rounded-r">Total</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {invoice.items.map((item, idx) => (
                                                            <tr key={idx} className="border-b">
                                                                <td className="p-2.5 font-medium">{item.product_name}</td>
                                                                {design.show_sku && <td className="p-2.5 font-mono text-gray-500">{item.product_sku || '—'}</td>}
                                                                <td className="p-2.5 text-center">{Number(item.unit_price).toFixed(2)}</td>
                                                                <td className="p-2.5 text-center">{item.quantity}</td>
                                                                <td className="p-2.5 text-right font-semibold">{(item.unit_price * item.quantity).toFixed(2)}</td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>

                                                <div className="ml-auto w-64 space-y-2 text-right text-xs pt-2">
                                                    <div className="flex justify-between font-bold text-sm border-t pt-2">
                                                        <span>TOTAL NET:</span>
                                                        <span style={{ color: design.theme_color }}>{currency} {Number(invoice.total_amount).toFixed(2)}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        );
                                    }

                                    if (block.id === 'footer_notes') {
                                        return (
                                            <div key={block.id} className="text-center text-[10px] text-gray-400 border-t pt-4">
                                                {settings.a4_footer_text || 'Merci pour votre confiance.'}
                                            </div>
                                        );
                                    }

                                    return null;
                                })}
                            </div>
                        </div>
                    )}

                </div>

                {/* أزرار الإغلاق والتحكم */}
                <div className="mt-6 flex gap-2 justify-end pt-4 border-t">
                    <button 
                        type="button"
                        onClick={onClose} 
                        className="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-800 rounded text-xs font-medium transition"
                    >
                        Close
                    </button>
                    <button 
                        type="button"
                        onClick={handlePrint} 
                        className="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded text-xs font-bold shadow-md transition"
                    >
                        Print (Ctrl+P)
                    </button>
                </div>

            </div>
        </div>
    );
}