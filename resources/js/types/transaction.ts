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

/** One category+amount line of a split transaction, with its own labels. */
export interface TransactionSplit {
    id: UUID;
    category_id: UUID | null;
    amount: number;
    category?: Category | null;
    labels?: Label[];
}

/** A single split line as submitted to the backend when (re)splitting. */
export interface SplitLineInput {
    category_id: UUID | null;
    amount: number;
    label_ids?: UUID[];
}

export interface Transaction {
    id: UUID;
    user_id: UUID;
    account_id: UUID;
    category_id: UUID | null;
    description: string;
    description_iv: string | null;
    transaction_date: string;
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
    splits?: TransactionSplit[];
    created_at: string;
    updated_at: string;
}

export interface ServerTransaction extends Transaction {
    account?: Account;
    category?: Category | null;
    labels?: Label[];
    splits?: TransactionSplit[];
}

export interface DecryptedTransaction extends Transaction {
    decryptedDescription: string;
    decryptedNotes: string | null;
    account?: Account;
    category?: Category | null;
    bank?: Bank;
    labels?: Label[];
    splits?: TransactionSplit[];
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
