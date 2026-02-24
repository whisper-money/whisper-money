interface AccountNameProps {
    account: {
        name: string;
        name_iv: string | null;
        encrypted: boolean;
    };
    className?: string;
}

export function AccountName({ account, className = '' }: AccountNameProps) {
    return <span className={className}>{account.name}</span>;
}
