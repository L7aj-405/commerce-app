import { useForm, router } from '@inertiajs/react';
import { Download, Eye, FileText, Paperclip, Trash2 } from 'lucide-react';
import Card from '@/Components/Card';
import EmptyState from '@/Components/EmptyState';
import Button from '@/Components/Button';
import { formatDateTime } from '@/Support/formatDate';

const ACCEPT = '.pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp';

/**
 * Documents/justificatifs card for the Expense Edit page. Upload/delete are
 * their own requests against the dedicated document routes — never bundled
 * into the expense update form, and never touching the ledger. See
 * FinanceExpenseDocumentController / FinanceDocumentController.
 */
export default function ExpenseDocumentsCard({ expense, canManage, documentTypes = [] }) {
    const documents = expense.documents ?? [];
    const { data, setData, post, processing, errors, reset } = useForm({
        documents: [], document_type: '', document_description: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(`/dashboard/finance/expenses/${expense.id}/documents`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    const removeDoc = (doc) => {
        if (confirm(`Supprimer "${doc.original_name}" ?`)) {
            router.delete(`/dashboard/finance/documents/${doc.id}`, { preserveScroll: true });
        }
    };

    return (
        <Card title="Documents justificatifs" subtitle="Factures, reçus, preuves de paiement — conservés même après annulation" className="max-w-3xl mt-6">
            {expense.status === 'paid' && (
                <div className="mb-4 rounded-lg border border-warning/30 bg-warning-soft px-3 py-2 text-sm text-warning">
                    Financial fields are locked, but supporting documents can still be added.
                </div>
            )}

            {documents.length === 0 ? (
                <EmptyState icon={FileText} title="Aucun justificatif ajouté" description={canManage ? 'Ajoutez une facture, un reçu ou une preuve de paiement ci-dessous.' : undefined} />
            ) : (
                <ul className="divide-y divide-line rounded-lg border border-line mb-5">
                    {documents.map((doc) => (
                        <li key={doc.id} className="flex items-center justify-between gap-3 px-3 py-2.5 text-sm">
                            <div className="flex items-center gap-2.5 min-w-0">
                                <FileText className="w-4 h-4 flex-shrink-0 text-content-muted" />
                                <div className="min-w-0">
                                    <p className="truncate text-content font-medium">{doc.original_name}</p>
                                    <p className="text-xs text-content-muted truncate">
                                        {documentTypeLabel(doc.document_type, documentTypes)} · {doc.human_size}
                                        {doc.uploaded_by?.name ? ` · par ${doc.uploaded_by.name}` : ''} · {formatDateTime(doc.created_at)}
                                    </p>
                                    {doc.description && <p className="text-xs text-content-muted truncate">{doc.description}</p>}
                                </div>
                            </div>
                            <div className="flex items-center gap-1 flex-shrink-0">
                                {doc.preview_url && (
                                    <a href={doc.preview_url} target="_blank" rel="noopener noreferrer" className="p-1.5 rounded-lg text-content-muted hover:text-content hover:bg-surface-3" aria-label="Voir" title="Voir">
                                        <Eye className="w-3.5 h-3.5" />
                                    </a>
                                )}
                                <a href={doc.download_url} className="p-1.5 rounded-lg text-content-muted hover:text-content hover:bg-surface-3" aria-label="Télécharger" title="Télécharger">
                                    <Download className="w-3.5 h-3.5" />
                                </a>
                                {canManage && (
                                    <button type="button" onClick={() => removeDoc(doc)} className="p-1.5 rounded-lg text-content-muted hover:text-danger hover:bg-danger-soft" aria-label="Supprimer" title="Supprimer">
                                        <Trash2 className="w-3.5 h-3.5" />
                                    </button>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            {canManage && (
                <form onSubmit={submit} className="space-y-3 pt-1 border-t border-line">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-3 mt-4">
                        <select value={data.document_type} onChange={(e) => setData('document_type', e.target.value)}
                            className="px-3 py-2 text-sm rounded-lg bg-surface-3 border border-line text-content">
                            <option value="">Type non spécifié</option>
                            {documentTypes.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                        </select>
                        <input type="text" value={data.document_description} onChange={(e) => setData('document_description', e.target.value)}
                            placeholder="Description (optionnel)"
                            className="px-3 py-2 text-sm rounded-lg bg-surface-3 border border-line text-content" />
                    </div>
                    <label className="flex items-center justify-center gap-2 px-4 py-4 rounded-lg border border-dashed border-line text-sm text-content-muted hover:border-primary hover:text-content cursor-pointer">
                        <Paperclip className="w-4 h-4" />
                        {data.documents.length > 0 ? `${data.documents.length} fichier(s) sélectionné(s)` : 'Choisir un ou plusieurs fichiers'}
                        <input type="file" multiple accept={ACCEPT} className="hidden" onChange={(e) => setData('documents', Array.from(e.target.files))} />
                    </label>
                    {errors.documents && <p className="text-xs text-danger">{errors.documents}</p>}
                    {errors['documents.0'] && <p className="text-xs text-danger">{errors['documents.0']}</p>}
                    <Button type="submit" variant="secondary" icon={Paperclip} loading={processing} disabled={data.documents.length === 0}>
                        {processing ? 'Envoi…' : 'Ajouter les documents'}
                    </Button>
                </form>
            )}
        </Card>
    );
}

function documentTypeLabel(value, documentTypes) {
    if (! value) return 'Non spécifié';
    return documentTypes.find((t) => t.value === value)?.label ?? value;
}
