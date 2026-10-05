import { type AccountType } from '@/types/account';
import {
    type Category,
    type CategoryColor,
    type CategoryType,
} from '@/types/category';
import { type DateFormat } from '@/types/import';

/** The app a full import comes from: it decides which layout is pre-filled. */
export type FullImportSource = 'banktrack' | 'generic';

/** Add to what the space holds, or delete its manual accounts first. */
export type FullImportMode = 'add' | 'wipe';

/**
 * The account "column" for a file that holds a single account: every row goes
 * to one account, named after the file.
 */
export const SINGLE_ACCOUNT_COLUMN = '__single_account__';

/** Which column of the file each piece of a movement comes from. */
export interface FullImportMapping {
    date: string | null;
    amount: string | null;
    description: string | null;
    /** A column, or {@link SINGLE_ACCOUNT_COLUMN}. */
    account: string | null;
    /** A second column that tells accounts of the same bank apart. */
    accountDetail: string | null;
    splitByAccountDetail: boolean;
    notes: string | null;
    category: string | null;
    /** What separates a parent from its child in the category cell. */
    categorySeparator: string;
    currency: string | null;
    balance: string | null;
    iban: string | null;
    externalId: string | null;
    ignored: string | null;
    dateFormat: DateFormat;
}

/** The fields of {@link FullImportMapping} that name a column. */
export type MappedColumn = Exclude<
    keyof FullImportMapping,
    'splitByAccountDetail' | 'categorySeparator' | 'dateFormat'
>;

/** One movement of the file, read with the mapping and ready to plan. */
export interface NormalizedRow {
    rowNumber: number;
    /** ISO `YYYY-MM-DD`. */
    date: string;
    /** Major units, signed: negative is money out. */
    amount: number;
    description: string;
    notes: string | null;
    accountKey: string;
    accountName: string;
    /** Parent first, at most three levels. Empty when uncategorized. */
    categoryPath: string[];
    /** A supported ISO code, or null to use the account's. */
    currency: string | null;
    /** Major units, or null when the row carries none. */
    balance: number | null;
    iban: string | null;
    externalId: string | null;
    ignored: boolean;
}

export interface UnreadableRow {
    rowNumber: number;
    reason: string;
}

export interface NormalizedFile {
    rows: NormalizedRow[];
    unreadable: UnreadableRow[];
    /** Fully blank rows in between, dropped while reading. */
    blankRows: number;
}

/** An account the file's rows point at. */
export interface FileAccount {
    key: string;
    name: string;
    count: number;
    from: string;
    to: string;
    /** The currency most of its rows are in, when the file says. */
    currency: string | null;
    balanceCount: number;
    lastBalance: { date: string; amount: number } | null;
    iban: string | null;
}

export type ImportAccountAction = 'create' | 'map' | 'merge' | 'skip';

/** The only account types an import may create: the ones with a ledger. */
export const IMPORT_ACCOUNT_TYPES = [
    'checking',
    'savings',
    'credit_card',
    'others',
] as const satisfies readonly AccountType[];

export type ImportAccountType = (typeof IMPORT_ACCOUNT_TYPES)[number];

export interface BankLite {
    id: string;
    name: string;
    logo: string | null;
}

/** What the import does with one account of the file. */
export interface AccountPlanEntry {
    action: ImportAccountAction;
    name: string;
    type: ImportAccountType;
    currencyCode: string;
    bank: BankLite | null;
    targetAccountId: string | null;
    /** The file account key this one is merged into. */
    mergeIntoKey: string | null;
}

/** A node of the category tree the file's category column describes. */
export interface FileCategoryNode {
    /** The normalised path, unique in the file. */
    id: string;
    name: string;
    parentId: string | null;
    /** 0 for a root. */
    depth: number;
    /** Rows filed directly under this node. */
    count: number;
    /** Rows in the whole subtree going out, and coming in. */
    outgoing: number;
    incoming: number;
}

export type CategoryMatchKind = 'exact' | 'similar';

/** What the import does with one category of the file. */
export interface CategoryPlanEntry {
    action: 'match' | 'create';
    categoryId: string | null;
    /** How sure the automatic match was; null once the user picked. */
    matchKind: CategoryMatchKind | null;
    type: CategoryType;
}

/** Where a kind of transfer goes unless the user says otherwise. */
export interface TransferTarget {
    category_id: string | null;
    name: string;
    icon: string;
    color: CategoryColor;
}

export interface ContextAccount {
    id: string;
    name: string;
    type: AccountType;
    currency_code: string;
    connected: boolean;
    archived: boolean;
    transactions_count: number;
    bank: BankLite | null;
}

export interface SavedProfile {
    columns: Partial<Record<MappedColumn, string | null>> | null;
    date_format: string | null;
    category_separator: string | null;
    split_accounts: boolean | null;
}

/** What the wizard starts from, before it reads the file. */
export interface FullImportContext {
    inOnboarding: boolean;
    aiAvailable: boolean;
    running: string | null;
    accounts: ContextAccount[];
    mappableAccountIds: string[];
    categories: Category[];
    defaultCategoryNames: { en: string; es: string }[];
    transferTargets: { own: TransferTarget; ignored: TransferTarget };
    profiles: Record<FullImportSource, SavedProfile | null>;
}

export type ImportStage =
    | 'upload'
    | 'queued'
    | 'wipe'
    | 'accounts'
    | 'categories'
    | 'transactions'
    | 'balances'
    | 'ai'
    | 'done';

export type ImportAiStatus =
    | 'skipped'
    | 'onboarding'
    | 'unavailable'
    | 'queued'
    | 'running'
    | 'done'
    | 'failed';

export interface ImportStats {
    stage?: ImportStage;
    wiped?: { accounts: number; transactions: number };
    accounts?: { created: number; mapped: number };
    categories?: { created: number; matched: number };
    transactions?: {
        total: number;
        processed: number;
        imported: number;
        duplicates: number;
        skipped: number;
    };
    balances?: { total: number; imported: number };
    uncategorized?: number;
    ai?: {
        status: ImportAiStatus;
        total?: number;
        processed?: number;
        applied?: number;
    };
    per_account?: {
        account_id: string;
        name: string;
        created: boolean;
        imported: number;
        duplicates: number;
    }[];
}

/** One import as the server reports it. */
export interface ImportStatus {
    id: string;
    source: FullImportSource;
    mode: FullImportMode;
    status: 'draft' | 'queued' | 'processing' | 'completed' | 'failed';
    file_name: string | null;
    error: string | null;
    created_at: string | null;
    finished_at: string | null;
    undone_at: string | null;
    stats: ImportStats;
}

/** What undoing an import would take out, as Settings lists it. */
export interface ImportUndoSummary {
    accounts: { name: string; transactions: number }[];
    categories: number;
    transactions: number;
    balances: number;
    /**
     * Rows on the accounts the import created that it did not write itself:
     * added by hand, or by another import. They go with those accounts.
     */
    later_transactions: number;
    into_own_accounts: { name: string; transactions: number }[];
}

export interface ImportHistoryEntry extends ImportStatus {
    undoable: boolean;
    summary: ImportUndoSummary | null;
}

/** A movement as the server takes it. */
export interface TransactionPayloadRow {
    account_key: string;
    date: string;
    amount: number;
    description: string;
    notes: string | null;
    category_key: string | null;
    external_id: string | null;
    currency_code: string | null;
}

/** A daily balance as the server takes it. */
export interface BalancePayloadRow {
    account_key: string;
    date: string;
    balance: number;
}

export interface AccountPayloadEntry {
    key: string;
    action: ImportAccountAction;
    name?: string;
    type?: ImportAccountType;
    currency_code?: string;
    bank_id?: string | null;
    iban?: string | null;
    target_account_id?: string;
    merge_into_key?: string;
}

export interface CategoryPayloadEntry {
    key: string;
    action: 'match' | 'create';
    category_id?: string;
    name?: string;
    parent_key?: string | null;
    type?: CategoryType;
    icon?: string;
    color?: CategoryColor;
}

export interface ImportPayload {
    source: FullImportSource;
    file_name: string | null;
    mode: FullImportMode;
    confirm_wipe?: true;
    profile: SavedProfile;
    accounts: AccountPayloadEntry[];
    categories: CategoryPayloadEntry[];
    expected_transactions: number;
    expected_balances: number;
}
