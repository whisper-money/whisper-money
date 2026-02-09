import { UUID } from './uuid';

export interface BankingConnection {
    id: UUID;
    provider: string;
    aspsp_name: string;
    aspsp_country: string;
    status: 'pending' | 'active' | 'expired' | 'revoked' | 'error';
    valid_until: string | null;
    last_synced_at: string | null;
    error_message: string | null;
    accounts_count: number;
    created_at: string;
    updated_at: string;
}

export interface EnableBankingInstitution {
    name: string;
    country: string;
    logo: string | null;
    maximum_consent_validity: number | null;
}
