import { EncryptedText } from '@/components/encrypted-text';
import { useEncryptionKey } from '@/contexts/encryption-key-context';
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

interface EncryptedTransactionDescriptionProps {
    encryptedText: string;
    iv: string;
    className?: string;
    length?: number | { min: number; max: number } | null;
}

export function EncryptedTransactionDescription({
    encryptedText,
    iv,
    className = '',
    length = null,
}: EncryptedTransactionDescriptionProps) {
    const { isKeySet } = useEncryptionKey();
    const { isPrivacyModeEnabled } = usePrivacyMode();

    const fakeDescription = useMemo(
        () => getFakeDescription(encryptedText),
        [encryptedText],
    );

    if (isPrivacyModeEnabled && isKeySet) {
        return <span className={className}>{fakeDescription}</span>;
    }

    return (
        <EncryptedText
            encryptedText={encryptedText}
            iv={iv}
            className={className}
            length={length}
        />
    );
}
