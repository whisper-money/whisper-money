export type CategorizationStatus = 'pending' | 'processing' | 'done' | 'failed';

export interface CategorizationProgress {
    status: CategorizationStatus;
    processed: number;
    total: number;
    applied: number;
}

export interface CategorizationKickoff {
    job_id: string;
    total: number;
}

export interface AiConsentResponse {
    consented: boolean;
    categorization: CategorizationKickoff | null;
}
