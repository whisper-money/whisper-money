import { Amount, useWidgetData, useWidgetEnv } from '@whisper-money/widget-sdk';
import { ArrowDownRight, ArrowUpRight } from 'lucide-react';

export default function Kpis() {
    const { kpis } = useWidgetData();
    const { currency } = useWidgetEnv();

    return (
        <div className="grid grid-cols-1 gap-3 p-4 @sm:grid-cols-2">
            <div className="rounded-lg border border-border p-3">
                <p className="flex items-center gap-1 text-xs text-muted-foreground">
                    <ArrowUpRight size={12} className="text-success" />
                    Ingresos
                </p>
                <Amount
                    amount={kpis.income}
                    currency={currency}
                    className="text-lg font-semibold"
                />
            </div>
            <div className="rounded-lg border border-border p-3">
                <p className="flex items-center gap-1 text-xs text-muted-foreground">
                    <ArrowDownRight size={12} className="text-destructive" />
                    Gastos
                </p>
                <Amount
                    amount={kpis.expenses}
                    currency={currency}
                    className="text-lg font-semibold"
                />
            </div>
        </div>
    );
}
