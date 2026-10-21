import {
    ChoiceCards,
    Notice,
    WizardScreen,
} from '@/components/full-import/full-import-layout';
import {
    countLabel,
    formatCount,
    formatFileSize,
    formatMonthRange,
    sourceLabel,
    transactionCount,
} from '@/lib/full-import-format';
import { SUPPORTED_IMPORT_EXTENSIONS } from '@/lib/transaction-import';
import { cn } from '@/lib/utils';
import documentation from '@/routes/documentation';
import { type FullImportSource } from '@/types/full-import';
import { __ } from '@/utils/i18n';
import { ExternalLink, FileSpreadsheet, Lock, Upload, X } from 'lucide-react';
import { useRef, useState, type DragEvent, type ReactNode } from 'react';

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
        value: 'money_pro',
        title: 'Money Pro',
        description:
            'Upload every file of the export at once: transfers and balances come in too',
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

/** A picked file as the list shows it. */
export interface FileEntry {
    file: File;
    /** The rows read from it, or null when it could not be read. */
    rows: number | null;
    /** Why it stays out of the import, or null when it goes in. */
    error: string | null;
}

interface StepFileProps {
    eyebrow: string;
    source: FullImportSource;
    onSourceChange: (source: FullImportSource) => void;
    files: FileEntry[];
    /** Names in the last pick that were already in the list, left out. */
    alreadyPicked: string[];
    summary: FileSummary | null;
    recognized: boolean;
    parsing: boolean;
    locale: string;
    onFilesSelect: (files: File[]) => void;
    onFileRemove: (file: File) => void;
    footer: ReactNode;
}

export function StepFile({
    eyebrow,
    source,
    onSourceChange,
    files,
    alreadyPicked,
    summary,
    recognized,
    parsing,
    locale,
    onFilesSelect,
    onFileRemove,
    footer,
}: StepFileProps) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [isDragging, setIsDragging] = useState(false);

    const pick = (picked: FileList | null | undefined) => {
        if (picked && picked.length > 0 && !parsing) {
            onFilesSelect(Array.from(picked));
        }
    };
    const browse = () => inputRef.current?.click();
    const dropTarget = {
        onDragOver: (event: DragEvent) => {
            event.preventDefault();
            setIsDragging(true);
        },
        onDragLeave: () => setIsDragging(false),
        onDrop: (event: DragEvent) => {
            event.preventDefault();
            setIsDragging(false);
            pick(event.dataTransfer.files);
        },
    };
    const errors = files.filter((entry) => entry.error !== null);
    const single = files.length === 1 && errors.length === 0;

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
                className="grid sm:grid-cols-3"
            />

            <div className="flex flex-col gap-2.5">
                <span className="font-medium">{__('File')}</span>
                <input
                    ref={inputRef}
                    type="file"
                    multiple
                    className="hidden"
                    accept={SUPPORTED_IMPORT_EXTENSIONS.join(',')}
                    data-testid="full-import-file-input"
                    onChange={(event) => {
                        pick(event.target.files);
                        // Cleared so picking the same file again still fires.
                        event.target.value = '';
                    }}
                />
                {files.length > 0 ? (
                    <div
                        {...dropTarget}
                        className={cn(
                            'flex flex-col gap-2 rounded-xl border border-dashed bg-muted/40 p-2 transition-colors',
                            isDragging && 'border-primary bg-accent',
                        )}
                    >
                        <ul className="flex flex-col gap-2">
                            {files.map((entry) => (
                                <PickedFileRow
                                    key={fileKey(entry.file)}
                                    entry={entry}
                                    details={
                                        single
                                            ? fileSummary(
                                                  summary,
                                                  parsing,
                                                  locale,
                                              )
                                            : entry.rows !== null
                                              ? rowCount(entry.rows, locale)
                                              : null
                                    }
                                    disabled={parsing}
                                    onRemove={() => onFileRemove(entry.file)}
                                />
                            ))}
                        </ul>
                        <div className="flex flex-wrap items-center justify-between gap-2 px-2 pb-1">
                            <span className="text-[13px] text-muted-foreground">
                                {!single &&
                                    [
                                        fileCount(
                                            files.length - errors.length,
                                            locale,
                                        ),
                                        fileSummary(summary, parsing, locale),
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                            </span>
                            <button
                                type="button"
                                className="min-h-9 rounded-md border bg-background px-3 text-sm font-medium hover:bg-accent disabled:opacity-50"
                                disabled={parsing}
                                onClick={browse}
                            >
                                {__('Add another file')}
                            </button>
                        </div>
                    </div>
                ) : (
                    <button
                        type="button"
                        {...dropTarget}
                        onClick={browse}
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
                            <span className="text-[13px] text-muted-foreground">
                                {__(
                                    'An export split into several files? Pick them all.',
                                )}
                            </span>
                        </span>
                    </button>
                )}

                {alreadyPicked.length > 0 && (
                    <Notice tone="muted">
                        {__('Already in the list: :files', {
                            files: alreadyPicked.join(', '),
                        })}
                    </Notice>
                )}

                {errors.map((entry) => (
                    <Notice key={fileKey(entry.file)} tone="danger">
                        {entry.error}
                    </Notice>
                ))}

                {recognized && errors.length === 0 && (
                    <Notice tone="success">
                        <span>
                            <strong className="font-semibold">
                                {__(':source format recognized.', {
                                    source: sourceLabel(source),
                                })}
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

/** Unique in the list: a name already in it is never added again. */
function fileKey(file: File): string {
    return file.name;
}

function fileCount(count: number, locale: string): string {
    return countLabel(
        count,
        __('1 file'),
        __(':count files', { count: formatCount(count, locale) }),
    );
}

/** "412 KB · 36 transactions · Jan 2024 – Sep 2026", without the size. */
function fileSummary(
    summary: FileSummary | null,
    parsing: boolean,
    locale: string,
): string {
    return [
        parsing
            ? __('Reading…')
            : summary && transactionCount(summary.rows, locale),
        summary?.from &&
            summary.to &&
            formatMonthRange(summary.from, summary.to, locale),
    ]
        .filter(Boolean)
        .join(' · ');
}

function rowCount(count: number, locale: string): string {
    return countLabel(
        count,
        __('1 row'),
        __(':count rows', { count: formatCount(count, locale) }),
    );
}

function PickedFileRow({
    entry,
    details,
    disabled,
    onRemove,
}: {
    entry: FileEntry;
    details: string | null;
    disabled: boolean;
    onRemove: () => void;
}) {
    return (
        <li className="flex items-center gap-3.5 rounded-lg p-2">
            <span
                className={cn(
                    'flex size-11 shrink-0 items-center justify-center rounded-lg border bg-background',
                    entry.error !== null && 'text-destructive',
                )}
            >
                <FileSpreadsheet className="size-5" />
            </span>
            <div className="min-w-0 flex-1">
                <div className="truncate font-medium">{entry.file.name}</div>
                <div className="text-[13px] text-muted-foreground">
                    {[formatFileSize(entry.file.size), details]
                        .filter(Boolean)
                        .join(' · ')}
                </div>
            </div>
            <button
                type="button"
                className="flex size-9 shrink-0 items-center justify-center rounded-md border bg-background hover:bg-accent disabled:opacity-50"
                disabled={disabled}
                aria-label={__('Remove :file', { file: entry.file.name })}
                onClick={onRemove}
            >
                <X className="size-4" />
            </button>
        </li>
    );
}
