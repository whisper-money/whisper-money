import { type Account, type Bank } from './account';
import { type Category } from './category';

export interface Transaction {
    id: string;
    user_id: number;
    account_id: number;
    category_id: number | null;
    description: string;
    description_iv: string;
    transaction_date: string;
    amount: string;
    currency_code: string;
    notes: string | null;
    notes_iv: string | null;
    created_at: string;
    updated_at: string;
}

export interface DecryptedTransaction extends Transaction {
    decryptedDescription: string;
    decryptedNotes: string | null;
    account?: Account;
    category?: Category | null;
    bank?: Bank;
}

export interface TransactionFilters {
    dateFrom: Date | null;
    dateTo: Date | null;
    amountMin: number | null;
    amountMax: number | null;
    categoryIds: number[];
    accountIds: number[];
    searchText: string;
}
