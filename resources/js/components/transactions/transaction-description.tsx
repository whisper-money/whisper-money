import { usePrivacyMode } from '@/contexts/privacy-mode-context';
import { type Transaction } from '@/types/transaction';
import { __ } from '@/utils/i18n';

/**
 * Built on every call, not at module load: translations arrive at runtime,
 * so a module-level list would be stuck in English.
 */
const fakeDescriptions = (): string[] => [
    __('Coffee Shop Purchase'),
    __('Grocery Store'),
    __('Online Subscription'),
    __('Restaurant Payment'),
    __('Gas Station'),
    __('Pharmacy Purchase'),
    __('Utility Bill Payment'),
    __('Mobile Phone Bill'),
    __('Insurance Premium'),
    __('Gym Membership'),
    __('Streaming Service'),
    __('Food Delivery'),
    __('Public Transport'),
    __('Parking Fee'),
    __('Hardware Store'),
    __('Clothing Store'),
    __('Electronics Purchase'),
    __('Medical Services'),
    __('Dental Payment'),
    __('Home Improvement'),
];

function getFakeDescription(seed: string): string {
    let hash = 0;
    for (let i = 0; i < seed.length; i++) {
        const char = seed.charCodeAt(i);
        hash = (hash << 5) - hash + char;
        hash = hash & hash;
    }
    const descriptions = fakeDescriptions();
    const index = Math.abs(hash) % descriptions.length;
    return descriptions[index];
}

/**
 * Shown in place of a still-encrypted legacy value before the user unlocks and
 * the decrypt-migration runs, so the list never renders raw ciphertext.
 */
export const ENCRYPTED_PLACEHOLDER = '••••••••••';

/** A row's description as plain text, the placeholder while it is still encrypted. */
export function readableDescription(
    transaction: Pick<Transaction, 'description' | 'description_iv'>,
): string {
    return transaction.description_iv
        ? ENCRYPTED_PLACEHOLDER
        : transaction.description;
}

interface TransactionDescriptionProps {
    text: string;
    className?: string;
    encrypted?: boolean;
}

export function TransactionDescription({
    text,
    className = '',
    encrypted = false,
}: TransactionDescriptionProps) {
    const { isPrivacyModeEnabled } = usePrivacyMode();

    if (encrypted) {
        return <span className={className}>{ENCRYPTED_PLACEHOLDER}</span>;
    }

    return (
        <span className={className}>
            {isPrivacyModeEnabled ? getFakeDescription(text) : text}
        </span>
    );
}

/** A short tag after a description, e.g. a widget's "Purchase". */
export function TransactionBadge({ text }: { text: string }) {
    return (
        <span
            className="shrink-0 self-center rounded-full border border-neutral-200 px-1.5 py-px text-[11px] font-medium text-neutral-700 dark:border-neutral-700 dark:text-neutral-300"
            data-testid="transaction-list-row-badge"
        >
            {text}
        </span>
    );
}
