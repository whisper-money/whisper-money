import { type Account, type Bank } from './account';
import { type Category } from './category';
import { type Label } from './label';
import { UUID } from './uuid';

export type TransactionSource = 'manually_created' | 'imported';

export type CategorySource = 'manual' | 'rule' | 'ai' | 'bank';

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
