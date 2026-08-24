import { type Label } from '@/types/label';
import { PiggyBank, Tag } from 'lucide-react';

/**
 * Labels backing a savings goal read as a piggy bank so they stand apart from
 * the labels a user manages by hand.
 */
export function LabelIcon({
    label,
    className,
}: {
    label: Pick<Label, 'source'>;
    className?: string;
}) {
    const Icon = label.source === 'saving_goal' ? PiggyBank : Tag;

    return <Icon className={className} />;
}
