import { cn } from '@/lib/utils';
import { AccountType, accountIconByType } from '@/types/account';

export function AccountTypeIcon({
    type,
    className,
}: {
    type: AccountType;
    className?: string;
}) {
    const Icon = accountIconByType(type);

    return (
        <Icon className={cn(['h-5 w-5 text-muted-foreground', className])} />
    );
}
