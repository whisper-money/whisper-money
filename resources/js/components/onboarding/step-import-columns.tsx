import { StepButton } from '@/components/onboarding/step-button';
import { StepList } from '@/components/onboarding/step-list';
import {
    StepCallout,
    StepEmphasis,
    StepScreen,
} from '@/components/onboarding/step-screen';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
} from '@/components/ui/select';
import { type ColumnMapping, type ColumnOption } from '@/types/import';
import { __ } from '@/utils/i18n';
import { ChevronRight } from 'lucide-react';

/** The fields worth a row on a phone. Everything else keeps what was guessed. */
const SHOWN_FIELDS = [
    'transaction_date',
    'description',
    'amount',
    'balance',
] as const;

type ShownField = (typeof SHOWN_FIELDS)[number];

/** Without these three a row is not a movement, so they gate the step. */
const REQUIRED_FIELDS: ShownField[] = [
    'transaction_date',
    'description',
    'amount',
];

function fieldLabel(field: ShownField): string {
    const labels: Record<ShownField, string> = {
        transaction_date: __('Date'),
        description: __('Description'),
        amount: __('Amount'),
        balance: __('Balance'),
    };

    return labels[field];
}

/** Radix needs a non-empty value, and "" is what an unmapped column is. */
const NO_COLUMN = '__none__';

/** A mapping value is one column, or several joined into the description. */
function columnLabel(value: ColumnMapping[ShownField]): string | null {
    if (Array.isArray(value)) {
        return value.length > 0 ? value.join(' + ') : null;
    }

    return value;
}

interface StepImportColumnsProps {
    fileName: string;
    columnOptions: ColumnOption[];
    mapping: ColumnMapping;
    onMappingChange: (field: ShownField, column: string | null) => void;
    onConfirm: () => void;
    onDifferentFile: () => void;
}

/**
 * The columns we guessed, for the user to disagree with.
 *
 * The screen leads with what was worked out rather than with empty selects:
 * most exports are read correctly, and the ones that are not only need one row
 * changing. That is the whole of the interaction — read four lines, fix none.
 */
export function StepImportColumns({
    fileName,
    columnOptions,
    mapping,
    onMappingChange,
    onConfirm,
    onDifferentFile,
}: StepImportColumnsProps) {
    const missing = REQUIRED_FIELDS.filter(
        (field) => columnLabel(mapping[field]) === null,
    );

    return (
        <StepScreen
            title={__('Did we read it right?')}
            description={
                <StepEmphasis
                    sentence={__(
                        'We worked out your columns from :file. Change anything that looks off.',
                    )}
                    word={fileName}
                />
            }
            footer={
                <>
                    <StepButton
                        text={__("That's right")}
                        disabled={missing.length > 0}
                        onClick={onConfirm}
                    />
                    <StepButton
                        text={__('Use a different file')}
                        variant="ghost"
                        onClick={onDifferentFile}
                    />
                </>
            }
        >
            <div className="flex flex-col gap-6">
                <StepList>
                    {SHOWN_FIELDS.map((field) => {
                        const column = columnLabel(mapping[field]);
                        const example = columnOptions.find(
                            (option) => option.value === column,
                        )?.examples[0];

                        return (
                            <Select
                                key={field}
                                value={column ?? NO_COLUMN}
                                onValueChange={(value) =>
                                    onMappingChange(
                                        field,
                                        value === NO_COLUMN ? null : value,
                                    )
                                }
                            >
                                <SelectTrigger
                                    aria-label={fieldLabel(field)}
                                    className="flex h-auto min-h-11 w-full items-center gap-3.5 rounded-sm border-0 py-4 text-left shadow-none focus-visible:ring-[3px] focus-visible:ring-ring/50 [&>svg:last-child]:hidden"
                                >
                                    {/* Divs rather than spans: the shared
                                        trigger line-clamps its direct span
                                        children, which flattens the column and
                                        its example onto one line. */}
                                    <div className="text-base font-medium">
                                        {fieldLabel(field)}
                                    </div>
                                    <div className="flex min-w-0 flex-1 flex-col items-end gap-0.5">
                                        <span className="truncate text-base">
                                            {column ?? __('Not in this file')}
                                        </span>
                                        {example !== undefined && (
                                            <span className="truncate text-sm text-muted-foreground">
                                                {String(example)}
                                            </span>
                                        )}
                                    </div>
                                    <ChevronRight className="size-5 shrink-0 text-muted-foreground/45" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NO_COLUMN}>
                                        {__('Not in this file')}
                                    </SelectItem>
                                    {columnOptions.map((option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        );
                    })}
                </StepList>

                <StepCallout>
                    {missing.length > 0 ? (
                        __(
                            'Pick the columns still missing above and we can carry on.',
                        )
                    ) : (
                        <StepEmphasis
                            sentence={__(
                                'We got all three right this time. :next — the layout is remembered for this account.',
                            )}
                            word={__("Next time we won't ask")}
                        />
                    )}
                </StepCallout>
            </div>
        </StepScreen>
    );
}
