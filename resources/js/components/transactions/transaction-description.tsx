import { usePrivacyMode } from '@/contexts/privacy-mode-context';
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

interface TransactionDescriptionProps {
    text: string;
    className?: string;
}

export function TransactionDescription({
    text,
    className = '',
}: TransactionDescriptionProps) {
    const { isPrivacyModeEnabled } = usePrivacyMode();

    return (
        <span className={className}>
            {isPrivacyModeEnabled ? getFakeDescription(text) : text}
        </span>
    );
}
