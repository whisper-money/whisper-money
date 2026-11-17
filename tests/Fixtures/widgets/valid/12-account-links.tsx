import {
    Amount,
    Button,
    useWidgetData,
    wm,
} from '@whisper-money/widget-sdk';
import { ArrowUpRight, Link } from 'lucide-react';

export default function AccountLinks() {
    const { accounts } = useWidgetData();
    const openGroceries = () =>
        wm.open({
            transactions: {
                search: 'Mercadona',
                dateFrom: '2026-10-01',
                dateTo: '2026-10-31',
            },
        });

    return (
        <div className="flex h-full flex-col gap-3 p-4">
            <div className="flex items-center justify-between gap-2">
                <h3 className="flex items-center gap-2 text-sm font-medium">
                    <Link size={14} />
                    Ir a mis cuentas
                </h3>
                <Button variant="ghost" size="sm" onClick={openGroceries}>
                    Súper de octubre
                    <ArrowUpRight size={14} />
                </Button>
            </div>
            <ul className="flex flex-col gap-1">
                {accounts.map((account) => (
                    <li
                        key={account.id}
                        className="flex cursor-pointer items-center justify-between gap-2 rounded-md px-2 py-1 hover:bg-muted"
                        onClick={() => wm.open({ account: account.id })}
                    >
                        <span className="truncate">{account.name}</span>
                        <Amount
                            amount={account.balance}
                            currency={account.currency}
                            className="tabular-nums"
                        />
                    </li>
                ))}
            </ul>
        </div>
    );
}
