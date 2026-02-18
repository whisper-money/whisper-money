type Length = number | { min: number; max: number } | null;

interface AccountNameProps {
    account: {
        name: string;
        name_iv: string | null;
        encrypted: boolean;
    };
    className?: string;
    length?: Length;
}

export function AccountName({
    account,
    className = '',
}: Omit<AccountNameProps, 'length'>) {
    return <span className={className}>{account.name}</span>;
}
