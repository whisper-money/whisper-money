import { Amount, BankLogo, useWidgetData } from '@whisper-money/widget-sdk';
import { Landmark } from 'lucide-react';

export default function Accounts() {
    const { accounts } = useWidgetData();

    return (
        <div className="flex flex-col gap-3 p-4">
            <h3 className="flex items-center gap-2 text-sm font-medium">
                <Landmark size={14} />
                Cuentas
            </h3>
            <ul className="flex flex-col gap-2">
                {accounts.map((account) => (
                    <li
                        key={account.id}
                        className="flex items-center justify-between gap-2"
                    >
                        <span className="flex items-center gap-2 truncate">
                            <BankLogo accountId={account.id} size={20} />
                            {account.name}
                        </span>
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
