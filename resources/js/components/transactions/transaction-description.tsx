import { usePrivacyMode } from '@/contexts/privacy-mode-context';
import { useMemo } from 'react';

const FAKE_DESCRIPTIONS = [
    'Coffee Shop Purchase',
    'Grocery Store',
    'Online Subscription',
    'Restaurant Payment',
    'Gas Station',
    'Pharmacy Purchase',
    'Utility Bill Payment',
    'Mobile Phone Bill',
    'Insurance Premium',
    'Gym Membership',
    'Streaming Service',
    'Food Delivery',
    'Public Transport',
    'Parking Fee',
    'Hardware Store',
    'Clothing Store',
    'Electronics Purchase',
    'Medical Services',
    'Dental Payment',
    'Home Improvement',
];

function getFakeDescription(seed: string): string {
    let hash = 0;
    for (let i = 0; i < seed.length; i++) {
        const char = seed.charCodeAt(i);
        hash = (hash << 5) - hash + char;
        hash = hash & hash;
    }
    const index = Math.abs(hash) % FAKE_DESCRIPTIONS.length;
    return FAKE_DESCRIPTIONS[index];
}

/**
 * Shown in place of a still-encrypted legacy value before the user unlocks and
 * the decrypt-migration runs, so the list never renders raw ciphertext.
 */
export const ENCRYPTED_PLACEHOLDER = '••••••••••';

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

    const fakeDescription = useMemo(() => getFakeDescription(text), [text]);

    if (encrypted) {
        return <span className={className}>{ENCRYPTED_PLACEHOLDER}</span>;
    }

    return (
        <span className={className}>
            {isPrivacyModeEnabled ? fakeDescription : text}
        </span>
    );
}
