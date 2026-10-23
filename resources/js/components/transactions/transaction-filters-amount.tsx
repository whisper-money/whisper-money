import { Input } from '@/components/ui/input';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    type AmountDirection,
    type AmountFilterInput,
    fromSignedAmountRange,
    type SignedAmountRange,
    toSignedAmountRange,
} from '@/lib/amount-filter';
import { __ } from '@/utils/i18n';

import { useState } from 'react';

interface AmountFilterFieldsProps extends SignedAmountRange {
    onChange: (range: SignedAmountRange) => void;
}

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

    function changeDirection(direction: AmountDirection) {
        // Leaving a signed range that spans both sides: its bounds become
        // sizes, smallest first.
        const sizes = [input.min, input.max].map((bound) =>
            bound === null ? null : Math.abs(bound),
        );
        const [min, max] =
            input.direction === null &&
            sizes[0] !== null &&
            sizes[1] !== null &&
            sizes[0] > sizes[1]
                ? [sizes[1], sizes[0]]
                : sizes;

        update({ direction, min, max });
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
                        changeDirection(value as AmountDirection);
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
                <Input
                    type="number"
                    step="0.01"
                    min={isSigned ? undefined : 0}
                    inputMode="decimal"
                    data-testid="filter-amount-min"
                    value={input.min ?? ''}
                    onChange={(e) =>
                        update({ ...input, min: parseBound(e.target.value) })
                    }
                    placeholder={__('Min')}
                    aria-label={__('Minimum amount')}
                />

                <Input
                    type="number"
                    step="0.01"
                    min={isSigned ? undefined : 0}
                    inputMode="decimal"
                    data-testid="filter-amount-max"
                    value={input.max ?? ''}
                    onChange={(e) =>
                        update({ ...input, max: parseBound(e.target.value) })
                    }
                    placeholder={__('Max')}
                    aria-label={__('Maximum amount')}
                />
            </div>

            {isSigned && (
                <p className="text-xs text-muted-foreground">
                    {__(
                        'This range covers both expenses and income. Pick a side to filter by size.',
                    )}
                </p>
            )}
        </div>
    );
}
