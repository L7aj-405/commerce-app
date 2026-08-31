import { FileText, Paperclip, X } from 'lucide-react';

const ACCEPT = '.pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp';

function humanSize(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    const kb = bytes / 1024;
    if (kb < 1024) return `${kb.toFixed(1)} KB`;
    return `${(kb / 1024).toFixed(1)} MB`;
}

/**
 * "Documents justificatifs" section for the Create Expense page. The expense
 * doesn't exist yet, so files stay pending client-side and are only actually
 * uploaded as part of the same POST that creates the expense — see
 * FinanceExpenseController::store(). Purely additive: never touches the
 * expense's own fields.
 */
export default function ExpenseDocumentPicker({ data, setData, errors, documentTypes = [] }) {
    const files = data.documents ?? [];

    const addFiles = (fileList) => {
        setData('documents', [...files, ...Array.from(fileList)]);
    };

    const removeFile = (index) => {
        setData('documents', files.filter((_, i) => i !== index));
    };

    return (
        <div className="space-y-4">
            <h3 className="text-sm font-semibold text-content">Documents justificatifs</h3>
            <p className="text-xs text-content-muted">Factures, reçus, preuves de paiement, bons carburant… PDF ou image, 10 Mo max par fichier.</p>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label className="block text-sm font-medium text-content-muted mb-1">Type de document</label>
                    <select value={data.document_type ?? ''} onChange={(e) => setData('document_type', e.target.value)}
                        className="w-full px-3 py-2 rounded-lg bg-surface-3 border border-line text-content focus:outline-none focus:ring-2 focus:ring-primary">
                        <option value="">Non spécifié</option>
                        {documentTypes.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                    </select>
                </div>
                <div>
                    <label className="block text-sm font-medium text-content-muted mb-1">Description</label>
                    <input type="text" value={data.document_description ?? ''} onChange={(e) => setData('document_description', e.target.value)}
                        placeholder="Optionnel"
                        className="w-full px-3 py-2 rounded-lg bg-surface-3 border border-line text-content focus:outline-none focus:ring-2 focus:ring-primary" />
                </div>
            </div>

            <label className="flex items-center justify-center gap-2 px-4 py-6 rounded-lg border border-dashed border-line text-sm text-content-muted hover:border-primary hover:text-content cursor-pointer">
                <Paperclip className="w-4 h-4" />
                Choisir un ou plusieurs fichiers (PDF, JPG, PNG, WEBP)
                <input type="file" multiple accept={ACCEPT} className="hidden" onChange={(e) => { addFiles(e.target.files); e.target.value = ''; }} />
            </label>
            {errors.documents && <p className="text-xs text-danger">{errors.documents}</p>}
            {errors['documents.0'] && <p className="text-xs text-danger">{errors['documents.0']}</p>}

            {files.length > 0 && (
                <ul className="divide-y divide-line rounded-lg border border-line">
                    {files.map((file, i) => (
                        <li key={`${file.name}-${i}`} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                            <span className="flex items-center gap-2 min-w-0">
                                <FileText className="w-4 h-4 flex-shrink-0 text-content-muted" />
                                <span className="truncate text-content">{file.name}</span>
                                <span className="text-content-muted flex-shrink-0">{humanSize(file.size)}</span>
                            </span>
                            <button type="button" onClick={() => removeFile(i)} className="p-1 rounded text-content-muted hover:text-danger hover:bg-danger-soft flex-shrink-0" aria-label="Retirer">
                                <X className="w-3.5 h-3.5" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
