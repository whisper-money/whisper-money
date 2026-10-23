import { Input } from '@/components/ui/input';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    type AmountDirection,
    type AmountFilterInput,
    fromSignedAmountRange,
    type SignedAmountRange,
    toSignedAmountRange,
    withAmountBound,
    withAmountDirection,
} from '@/lib/amount-filter';
import { __ } from '@/utils/i18n';

import { useState } from 'react';

interface AmountFilterFieldsProps extends SignedAmountRange {
    onChange: (range: SignedAmountRange) => void;
}

const BOUNDS = [
    {
        bound: 'min',
        placeholder: () => __('Min'),
        label: () => __('Minimum amount'),
    },
    {
        bound: 'max',
        placeholder: () => __('Max'),
        label: () => __('Maximum amount'),
    },
] as const;

function parseBound(value: string): number | null {
    return value === '' ? null : parseFloat(value);
}

function sameRange(a: SignedAmountRange, b: SignedAmountRange): boolean {
    return a.amountMin === b.amountMin && a.amountMax === b.amountMax;
}

/**
 * Min/Max are typed as positive amounts and the Expense/Income toggle picks
 * the side, so "0 to 30" finds small expenses instead of only income. What
 * was typed is kept locally: the signed range alone cannot tell an empty
 * Expense min from a typed 0, and the inputs must not rewrite themselves
 * while the user types.
 */
export function AmountFilterFields({
    amountMin,
    amountMax,
    onChange,
}: AmountFilterFieldsProps) {
    const [input, setInput] = useState<AmountFilterInput>(() =>
        fromSignedAmountRange({ amountMin, amountMax }, 'expense'),
    );
    // The range last seen from the parent, to tell its echo of our own change
    // apart from a range replaced from outside.
    const [lastRange, setLastRange] = useState<SignedAmountRange>({
        amountMin,
        amountMax,
    });

    // The range was replaced from outside (Clear, a saved filter, the URL):
    // load it, keeping the side the user had picked when it is now empty.
    if (!sameRange(lastRange, { amountMin, amountMax })) {
        setLastRange({ amountMin, amountMax });
        if (!sameRange(toSignedAmountRange(input), { amountMin, amountMax })) {
            setInput(
                fromSignedAmountRange(
                    { amountMin, amountMax },
                    input.direction ?? 'expense',
                ),
            );
        }
    }

    function update(next: AmountFilterInput) {
        setInput(next);
        const range = toSignedAmountRange(next);
        setLastRange(range);
        onChange(range);
    }

    const isSigned = input.direction === null;

    return (
        <div className="flex flex-col gap-2 pt-2">
            <ToggleGroup
                type="single"
                variant="outline"
                className="w-full"
                value={input.direction ?? ''}
                onValueChange={(value) => {
                    if (value) {
                        update(
                            withAmountDirection(
                                input,
                                value as AmountDirection,
                            ),
                        );
                    }
                }}
                aria-label={__('Amount type')}
            >
                <ToggleGroupItem
                    value="expense"
                    className="flex-1"
                    data-testid="filter-amount-expense"
                >
                    {__('Expense')}
                </ToggleGroupItem>
                <ToggleGroupItem
                    value="income"
                    className="flex-1"
                    data-testid="filter-amount-income"
                >
                    {__('Income')}
                </ToggleGroupItem>
            </ToggleGroup>

            <div className="grid grid-cols-2 gap-2">
                {BOUNDS.map(({ bound, placeholder, label }) => (
                    <Input
                        key={bound}
                        type="number"
                        step="0.01"
                        min={isSigned ? undefined : 0}
                        inputMode="decimal"
                        data-testid={`filter-amount-${bound}`}
                        value={input[bound] ?? ''}
                        onChange={(e) =>
                            update(
                                withAmountBound(
                                    input,
                                    bound,
                                    parseBound(e.target.value),
                                ),
                            )
                        }
                        placeholder={placeholder()}
                        aria-label={label()}
                    />
                ))}
            </div>

            {isSigned && (
                <p className="text-xs text-muted-foreground">
                    {__(
                        'This range covers both expenses and income. Picking a side turns its bounds into sizes.',
                    )}
                </p>
            )}
        </div>
    );
}
