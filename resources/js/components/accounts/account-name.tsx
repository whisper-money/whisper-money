import { EncryptedText } from '@/components/encrypted-text';

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
    length = null,
}: AccountNameProps) {
    if (!account.encrypted) {
        return <span className={className}>{account.name}</span>;
    }

    return (
        <EncryptedText
            encryptedText={account.name}
            iv={account.name_iv!}
            className={className}
            length={length}
        />
    );
}
