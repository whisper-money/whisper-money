import { UUID } from './uuid';

export interface BankingConnection {
    id: UUID;
    provider: string;
    aspsp_name: string;
    aspsp_country: string;
    status:
        | 'pending'
        | 'awaiting_mapping'
        | 'active'
        | 'expired'
        | 'revoked'
        | 'error';
    valid_until: string | null;
    last_synced_at: string | null;
    error_message: string | null;
    accounts_count: number;
    has_pending_accounts?: boolean;
    created_at: string;
    updated_at: string;
}

export interface PendingBankAccount {
    uid: string;
    currency: string;
    name?: string;
    account_id?: {
        iban?: string;
    };
}

export interface DiscoveredBankAccount {
    uid: string;
    name: string | null;
    currency: string | null;
    iban: string | null;
}

export interface EnableBankingInstitution {
    name: string;
    country: string;
    logo: string | null;
    maximum_consent_validity: number | null;
}
