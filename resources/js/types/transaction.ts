import { type Account, type Bank } from './account';
import { type Category } from './category';
import { type Label } from './label';
import { UUID } from './uuid';

// Mirrors App\Enums\TransactionSource (PHP). Keep both in sync.
export type TransactionSource =
    | 'manually_created'
    | 'imported'
    | 'enablebanking'
    | 'wise';

export type CategorySource = 'manual' | 'rule' | 'ai' | 'bank';

/**
 * One part of a split, as it arrives on every part of the same split (itself
 * included). Enough to explain the split without reading the original, which is
 * soft-deleted server-side.
 */
export interface TransactionSplitSibling {
    id: UUID;
    split_parent_id: UUID;
    category_id: UUID | null;
    amount: number;
}

export interface Transaction {
    id: UUID;
    user_id: UUID;
    account_id: UUID;
    /** Set when this transaction is one part of a split. Absent on payloads the app sends. */
    split_parent_id?: UUID | null;
    /** Every part of the same split, this one included. Loaded where the table needs it. */
    split_siblings?: TransactionSplitSibling[];
    category_id: UUID | null;
    description: string;
    description_iv: string | null;
    transaction_date: string;
    /** Where the bank or the import file dated this row, kept once the user moved it. Null when never moved. */
    source_date?: string | null;
    amount: number;
    currency_code: string;
    notes: string | null;
    notes_iv: string | null;
    creditor_name?: string | null;
    debtor_name?: string | null;
    source: TransactionSource;
    category_source?: CategorySource | null;
    ai_confidence?: number | null;
    ai_categorized?: boolean;
    label_ids?: UUID[];
    created_at: string;
    updated_at: string;
}

export interface ServerTransaction extends Transaction {
    account?: Account;
    category?: Category | null;
    labels?: Label[];
}

export interface DecryptedTransaction extends Transaction {
    decryptedDescription: string;
    decryptedNotes: string | null;
    account?: Account;
    category?: Category | null;
    bank?: Bank;
    labels?: Label[];
}

export interface TransactionFilters {
    dateFrom: Date | null;
    dateTo: Date | null;
    amountMin: number | null;
    amountMax: number | null;
    categoryIds: UUID[];
    accountIds: UUID[];
    labelIds: UUID[];
    creditorName: string;
    debtorName: string;
    searchText: string;
    aiCategorizedOnly: boolean;
}
