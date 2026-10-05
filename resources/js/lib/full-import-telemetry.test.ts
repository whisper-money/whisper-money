import { type BuiltImport } from '@/lib/full-import-plan';
import {
    fileExtension,
    fileParsedProperties,
    outcomeProperties,
    reportImportError,
    shouldReportImportError,
    submittedProperties,
} from '@/lib/full-import-telemetry';
import { type AccountPlanEntry, type ImportStatus } from '@/types/full-import';
import { AxiosError, type AxiosResponse } from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const leaving = vi.hoisted(() => ({ value: false }));
const captureException = vi.hoisted(() => vi.fn());

vi.mock('@/lib/leave-page', () => ({ isLeavingPage: () => leaving.value }));
vi.mock('@/lib/posthog', () => ({ captureEvent: vi.fn() }));
vi.mock('@sentry/react', () => ({ captureException }));

function httpError(status?: number): AxiosError {
    return new AxiosError(
        'Request failed',
        status ? 'ERR_BAD_RESPONSE' : 'ERR_NETWORK',
        undefined,
        undefined,
        status ? ({ status } as AxiosResponse) : undefined,
    );
}

describe('shouldReportImportError', () => {
    beforeEach(() => {
        leaving.value = false;
    });

    it('reports a server error and a dropped connection', () => {
        expect(shouldReportImportError(httpError(500))).toBe(true);
        expect(shouldReportImportError(httpError(503))).toBe(true);
        expect(shouldReportImportError(httpError())).toBe(true);
    });

    it('never reports a refusal', () => {
        for (const status of [403, 404, 409, 422]) {
            expect(shouldReportImportError(httpError(status))).toBe(false);
        }
    });

    it('stays quiet while the page is going away', () => {
        leaving.value = true;

        expect(shouldReportImportError(httpError())).toBe(false);
    });

    it('reports an unexpected error that is not a request', () => {
        expect(shouldReportImportError(new TypeError('boom'))).toBe(true);
    });
});

describe('reportImportError', () => {
    beforeEach(() => {
        leaving.value = false;
        captureException.mockReset();
    });

    it('tags the feature and the stage, with nothing of the file', () => {
        const error = httpError(500);

        reportImportError(error, 'chunks', {
            entry: 'settings',
            source: 'banktrack',
            rows: 12,
        });

        expect(captureException).toHaveBeenCalledWith(error, {
            tags: { feature: 'full_import', stage: 'chunks' },
            extra: { entry: 'settings', source: 'banktrack', rows: 12 },
        });
    });

    it('drops what should not be reported', () => {
        reportImportError(httpError(422), 'create', { entry: 'onboarding' });

        expect(captureException).not.toHaveBeenCalled();
    });
});

describe('fileParsedProperties', () => {
    it('sends no headers for a recognised file', () => {
        expect(
            fileParsedProperties(
                { name: 'My Bank Export.CSV' },
                ['Fecha', 'Importe'],
                { count: 12, blank: 1 },
                'banktrack',
                true,
            ),
        ).toEqual({
            recognized: true,
            source: 'banktrack',
            extension: 'csv',
            rows: 12,
            skipped_blank_rows: 1,
            columns_count: 2,
        });
    });

    it('sends at most 40 headers of at most 60 characters otherwise', () => {
        const headers = Array.from(
            { length: 45 },
            (_, index) => `${'x'.repeat(70)}${index}`,
        );
        const properties = fileParsedProperties(
            { name: 'export.xlsx' },
            headers,
            { count: 3, blank: 0 },
            'generic',
            false,
        );

        expect(properties.headers).toHaveLength(40);
        expect(
            (properties.headers as string[]).every(
                (header) => header.length === 60,
            ),
        ).toBe(true);
        expect(properties.columns_count).toBe(45);
    });

    it('keeps the extension and nothing of the name', () => {
        expect(fileExtension('Juan García banktrack.csv')).toBe('csv');
        expect(fileExtension('no-extension')).toBe('none');
    });
});

describe('submittedProperties', () => {
    const entry = (overrides: Partial<AccountPlanEntry>): AccountPlanEntry => ({
        action: 'create',
        name: 'Account',
        type: 'checking',
        currencyCode: 'EUR',
        bank: null,
        newBankName: null,
        targetAccountId: null,
        mergeIntoKey: null,
        ...overrides,
    });

    it('counts the plan and leaves every name out', () => {
        const built = {
            payload: {
                categories: [
                    { key: 'own', action: 'match', category_id: 'x' },
                    { key: 'ignored', action: 'create', name: 'Other' },
                    { key: 'c0', action: 'create', name: 'Empresa' },
                    { key: 'c1', action: 'match', category_id: 'y' },
                ],
            },
            transactions: [{}, {}, {}],
            balances: [{}],
        } as unknown as BuiltImport;

        const properties = submittedProperties({
            mode: 'add',
            source: 'banktrack',
            built,
            accountPlan: {
                a: entry({ newBankName: 'MyInvestor' }),
                b: entry({ newBankName: 'myinvestor ' }),
                c: entry({ bank: { id: 'bank', name: 'Wise', logo: null } }),
                d: entry({ action: 'map', targetAccountId: 'own' }),
                e: entry({ action: 'merge', mergeIntoKey: 'a' }),
                f: entry({ action: 'skip' }),
            },
            uncategorized: 2,
            estimatedDuplicates: null,
            entry: 'onboarding',
        });

        expect(properties).toEqual({
            mode: 'add',
            source: 'banktrack',
            rows: 3,
            balances: 1,
            accounts_new: 3,
            accounts_mapped: 1,
            accounts_merged: 1,
            accounts_skipped: 1,
            banks_new: 1,
            categories_new: 1,
            categories_matched: 1,
            uncategorized: 2,
            estimated_duplicates: null,
            entry: 'onboarding',
        });
        expect(JSON.stringify(properties)).not.toMatch(/MyInvestor|Empresa/i);
    });
});

describe('outcomeProperties', () => {
    const status: ImportStatus = {
        id: 'import-1',
        source: 'banktrack',
        mode: 'add',
        status: 'completed',
        file_name: 'x.csv',
        error: null,
        created_at: '2026-10-05T10:00:00Z',
        finished_at: '2026-10-05T10:01:30Z',
        undone_at: null,
        stats: {
            transactions: {
                total: 10,
                processed: 10,
                imported: 8,
                duplicates: 2,
                skipped: 0,
            },
            ai: { status: 'queued' },
        },
    };

    it('takes the duration from the server when it has both ends', () => {
        expect(outcomeProperties(status, 0, 0, 'settings')).toEqual({
            imported: 8,
            skipped_duplicates: 2,
            duration_seconds: 90,
            ai_status: 'queued',
            entry: 'settings',
        });
    });

    it('falls back to how long this screen watched it', () => {
        expect(
            outcomeProperties(
                { ...status, finished_at: null },
                1_000,
                6_000,
                'settings',
            ).duration_seconds,
        ).toBe(5);
    });
});
