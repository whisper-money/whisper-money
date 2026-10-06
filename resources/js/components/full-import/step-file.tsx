import {
    ChoiceCards,
    Notice,
    WizardScreen,
} from '@/components/full-import/full-import-layout';
import {
    formatFileSize,
    formatMonthRange,
    transactionCount,
} from '@/lib/full-import-format';
import { SUPPORTED_IMPORT_EXTENSIONS } from '@/lib/transaction-import';
import { cn } from '@/lib/utils';
import documentation from '@/routes/documentation';
import { type FullImportSource } from '@/types/full-import';
import { __ } from '@/utils/i18n';
import { ExternalLink, FileSpreadsheet, Lock, Upload } from 'lucide-react';
import { useRef, useState, type ReactNode } from 'react';

const SOURCES: {
    value: FullImportSource;
    title: string;
    description: string;
}[] = [
    {
        value: 'banktrack',
        title: 'Banktrack',
        description: 'Columns, accounts and categories set up for you',
    },
    {
        value: 'generic',
        title: 'Another app or my own spreadsheet',
        description:
            'You choose what each column is, and we remember it next time',
    },
];

const EXPORT_STEPS = [
    'In Banktrack, open the «Transacciones» module.',
    'Leave the bank and account filter empty and pick the widest period: the download only includes what you see on screen.',
    'Click «Descargar» and choose CSV or XLSX.',
    'Upload the file here as it comes: no need to open it, rename columns or delete rows.',
];

export interface FileSummary {
    rows: number;
    from: string | null;
    to: string | null;
}

interface StepFileProps {
    eyebrow: string;
    source: FullImportSource;
    onSourceChange: (source: FullImportSource) => void;
    file: File | null;
    summary: FileSummary | null;
    recognized: boolean;
    error: string | null;
    parsing: boolean;
    locale: string;
    onFileSelect: (file: File) => void;
    footer: ReactNode;
}

export function StepFile({
    eyebrow,
    source,
    onSourceChange,
    file,
    summary,
    recognized,
    error,
    parsing,
    locale,
    onFileSelect,
    footer,
}: StepFileProps) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [isDragging, setIsDragging] = useState(false);

    const pick = (picked: File | undefined) => {
        if (picked) {
            onFileSelect(picked);
        }
    };

    return (
        <WizardScreen
            eyebrow={eyebrow}
            title={__('Where are you coming from?')}
            description={__(
                'Upload the export from your previous app and we turn it into Whisper Money accounts, categories and transactions.',
            )}
            footer={footer}
        >
            <ChoiceCards
                legend={__('Source app')}
                value={source}
                options={SOURCES.map((option) => ({
                    value: option.value,
                    title: __(option.title),
                    description: __(option.description),
                }))}
                onChange={onSourceChange}
                className="grid sm:grid-cols-2"
            />

            <div className="flex flex-col gap-2.5">
                <span className="font-medium">{__('File')}</span>
                <input
                    ref={inputRef}
                    type="file"
                    className="hidden"
                    accept={SUPPORTED_IMPORT_EXTENSIONS.join(',')}
                    data-testid="full-import-file-input"
                    onChange={(event) => {
                        pick(event.target.files?.[0]);
                        // Cleared so picking the same file again still fires.
                        event.target.value = '';
                    }}
                />
                {file ? (
                    <div className="flex flex-wrap items-center gap-3.5 rounded-xl border border-dashed bg-muted/40 p-4">
                        <span className="flex size-11 shrink-0 items-center justify-center rounded-lg border bg-background">
                            <FileSpreadsheet className="size-5" />
                        </span>
                        <div className="min-w-0 flex-1">
                            <div className="truncate font-medium">
                                {file.name}
                            </div>
                            <div className="text-[13px] text-muted-foreground">
                                {[
                                    formatFileSize(file.size),
                                    parsing
                                        ? __('Reading…')
                                        : summary &&
                                          transactionCount(
                                              summary.rows,
                                              locale,
                                          ),
                                    summary?.from &&
                                        summary.to &&
                                        formatMonthRange(
                                            summary.from,
                                            summary.to,
                                            locale,
                                        ),
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </div>
                        </div>
                        <button
                            type="button"
                            className="min-h-9 rounded-md border bg-background px-3 text-sm font-medium hover:bg-accent"
                            onClick={() => inputRef.current?.click()}
                        >
                            {__('Change file')}
                        </button>
                    </div>
                ) : (
                    <button
                        type="button"
                        onDragOver={(event) => {
                            event.preventDefault();
                            setIsDragging(true);
                        }}
                        onDragLeave={() => setIsDragging(false)}
                        onDrop={(event) => {
                            event.preventDefault();
                            setIsDragging(false);
                            pick(event.dataTransfer.files[0]);
                        }}
                        onClick={() => inputRef.current?.click()}
                        className={cn(
                            'flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed px-6 py-10 text-center transition-colors hover:border-foreground/30',
                            isDragging && 'border-primary bg-accent',
                        )}
                    >
                        <Upload className="size-6 text-muted-foreground" />
                        <span className="flex flex-col gap-1">
                            <span className="font-medium">
                                {__('Drop the file here, or click to browse')}
                            </span>
                        </span>
                    </button>
                )}

                {error && <Notice tone="danger">{error}</Notice>}

                {recognized && !error && (
                    <Notice tone="success">
                        <span>
                            <strong className="font-semibold">
                                {__('Banktrack format recognized.')}
                            </strong>{' '}
                            {__(
                                'We filled in the columns for you; you check them in a moment.',
                            )}
                        </span>
                    </Notice>
                )}

                <span className="text-[13px] text-muted-foreground">
                    {__('CSV, XLS, XLSX or Numbers, up to 10 MB.')}
                </span>
            </div>

            {source === 'banktrack' && (
                <details className="rounded-xl border px-4 py-3.5 sm:px-5">
                    <summary className="cursor-pointer font-medium">
                        {__('How to export your data from Banktrack')}
                    </summary>
                    <ol className="mt-3 flex list-decimal flex-col gap-1.5 pl-5 text-sm text-muted-foreground">
                        {EXPORT_STEPS.map((step) => (
                            <li key={step}>{__(step)}</li>
                        ))}
                    </ol>
                    {/* A new tab, so leaving for the guide keeps the file
                        already read here. */}
                    <a
                        href={documentation.show.url(
                            'import-from-another-app/banktrack',
                        )}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="mt-3 inline-flex items-center gap-1.5 text-sm font-medium text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                    >
                        {__('Read the full guide')}
                        <ExternalLink className="size-3.5" aria-hidden="true" />
                    </a>
                </details>
            )}

            <p className="flex items-start gap-2.5 text-[13px] text-muted-foreground">
                <Lock className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                {__(
                    'The file is read in your browser. We only save what you confirm in the last step, and it never leaves Whisper Money.',
                )}
            </p>
        </WizardScreen>
    );
}
