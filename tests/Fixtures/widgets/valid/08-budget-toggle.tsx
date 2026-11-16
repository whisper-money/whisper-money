import { Badge, cn, useWidgetData } from '@whisper-money/widget-sdk';
import { useState } from 'react';

export default function Budgets() {
    const { budgets } = useWidgetData();
    const [showAll, setShowAll] = useState(false);
    const toggle = () => setShowAll(!showAll);
    const visible = showAll ? budgets : budgets.slice(0, 2);

    return (
        <div className="flex flex-col gap-2 p-4">
            <div className="flex items-center justify-between">
                <h3 className="text-sm font-medium">Presupuestos</h3>
                <button
                    type="button"
                    className="text-xs underline"
                    onClick={toggle}
                >
                    {showAll ? 'Ver menos' : 'Ver todos'}
                </button>
            </div>
            {visible.map((budget) => (
                <div
                    key={budget.name}
                    className="flex items-center justify-between"
                >
                    <span
                        className={cn(
                            'text-sm',
                            budget.spent > budget.limit && 'text-destructive',
                        )}
                    >
                        {budget.name}
                    </span>
                    <Badge
                        variant={
                            budget.spent > budget.limit
                                ? 'destructive'
                                : 'secondary'
                        }
                    >
                        {Math.round((budget.spent / budget.limit) * 100)}%
                    </Badge>
                </div>
            ))}
        </div>
    );
}
