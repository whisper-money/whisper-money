import { UUID } from './uuid';

export interface BankingConnection {
    id: UUID;
    provider: string;
    aspsp_name: string;
    aspsp_country: string;
    /** Null until `banking:backfill-aspsp-beta` has seen this connection. */
    aspsp_beta?: boolean | null;
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
    next_sync_attempt_at?: string | null;
    can_sync_manually?: boolean;
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
    /** The provider's own "this connector is not stable yet" flag. Absent on
     *  the providers we integrate natively, which have no such notion. */
    beta?: boolean;
}
