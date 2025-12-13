import { type Account, type Bank } from './account';
import { type Category } from './category';
import { type Label } from './label';
import { UUID } from './uuid';

export type TransactionSource = 'manually_created' | 'imported';

export interface Transaction {
    id: UUID;
    user_id: UUID;
    account_id: UUID;
    category_id: UUID | null;
    description: string;
    description_iv: string;
    transaction_date: string;
    amount: number;
    currency_code: string;
    notes: string | null;
    notes_iv: string | null;
    source: TransactionSource;
    label_ids?: UUID[];
    created_at: string;
    updated_at: string;
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
    searchText: string;
}
