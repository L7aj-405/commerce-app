import { FileCheck2, Wallet, AlertTriangle, Eye, CheckCircle2, XCircle, HelpCircle, ShieldCheck, ShieldAlert } from 'lucide-react';

const STYLE = {
    slate: 'bg-slate-500/15 text-slate-600 dark:text-slate-300',
    blue: 'bg-blue-500/15 text-blue-700 dark:text-blue-300',
    amber: 'bg-warning-soft text-warning',
    green: 'bg-success-soft text-success',
    red: 'bg-danger-soft text-danger',
};

function Badge({ icon: Icon, color, children }) {
    return (
        <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium ${STYLE[color] ?? STYLE.slate}`}>
            <Icon className="w-3 h-3" /> {children}
        </span>
    );
}

/**
 * Justification/documentation badges for an expense row or the Edit page —
 * mirrors App\Enums\FinanceExpenseJustificationStatus / OwnerReviewStatus and
 * the fiscal_ready accessor. "Official document attached" only ever shows
 * for a REAL official-typed document (invoice/receipt/payment_proof/
 * fuel_ticket/supplier_invoice — see FinanceDocumentType::officialTypes());
 * an internal_voucher document, however thorough, never earns it — see
 * FinanceExpenseService's class docblock.
 */
export default function JustificationBadges({ expense }) {
    const docs = expense.documents ?? [];
    const officialDoc = docs.find((d) => d.is_official_document);
    const hasVoucherPhoto = docs.some((d) => d.is_internal_document);

    return (
        <>
            {expense.justification_status === 'documented' && (
                <Badge icon={FileCheck2} color="green">{officialDoc ? `${docLabel(officialDoc)} attached` : 'Official document attached'}</Badge>
            )}
            {expense.justification_status === 'internal_only' && (
                <Badge icon={Wallet} color="blue">Internal voucher{hasVoucherPhoto ? ' attached' : ' declared'}</Badge>
            )}
            {expense.justification_status === 'needs_review' && (
                <Badge icon={AlertTriangle} color="amber">No official invoice</Badge>
            )}
            {expense.fiscal_ready
                ? <Badge icon={ShieldCheck} color="green">Fiscal-ready</Badge>
                : <Badge icon={ShieldAlert} color="slate">Not fiscal-ready</Badge>}
            {expense.owner_review_status === 'pending' && <Badge icon={Eye} color="amber">Needs owner review</Badge>}
            {expense.owner_review_status === 'approved' && <Badge icon={CheckCircle2} color="green">Approved internally</Badge>}
            {expense.owner_review_status === 'rejected' && <Badge icon={XCircle} color="red">Rejected</Badge>}
            {expense.owner_review_status === 'needs_more_info' && <Badge icon={HelpCircle} color="amber">Needs more info</Badge>}
        </>
    );
}

function docLabel(doc) {
    return { invoice: 'Invoice', receipt: 'Receipt', payment_proof: 'Payment proof', fuel_ticket: 'Fuel ticket', supplier_invoice: 'Supplier invoice' }[doc.document_type] ?? 'Document';
}
