import { cn } from "@/lib/utils";
import { AccountType } from "@/types/account";
import { BadgeQuestionMarkIcon, Building2, CreditCard, FolderKanban, Landmark, LucideIcon, PiggyBank } from "lucide-react";

export function AccountTypeIcon({ type, className }: { type: AccountType, className?: string }) {
    const typeMap: Record<AccountType, LucideIcon> = {
        checking: Building2,     // 🏦 - bank / institution
        credit_card: CreditCard, // 💳 - card
        loan: Landmark,          // 🏠 - "institution/loan", or use Home if it's a mortgage
        savings: PiggyBank,      // 💰 - savings
        others: FolderKanban,    // 📁 - miscellaneous/other
    };

    const Icon = typeMap[type] || BadgeQuestionMarkIcon;

    return <Icon className={cn([
        "h-5 w-5 text-muted-foreground",
        className
    ])} />;
}
