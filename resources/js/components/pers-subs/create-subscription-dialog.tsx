import { router, usePage } from "@inertiajs/react";
import { SharedData } from '@/types';
import React, { use, useState } from "react";
import { SubscriptionPeriodType } from "@/types/personal-subscriptions";
import { Category } from "@/types/category";
import { Label } from "@/types/label";
import { __ } from "@/utils/i18n";
import { store } from "@/routes/accounts";
import { Dialog } from "../ui/dialog";

interface Props {
    className?: string;
    currencyCode?: string;
    trigger?: React.ReactNode;
}

export function CreateSubscriptionDialog({
    className = '',
    currencyCode = 'USD',
    trigger
}: Props) {
    const page = usePage<SharedData>();
    const [open, setOpen] = useState(false);
    const [name, setName] = useState('');
    const [periodType, setPeriodType] = useState<SubscriptionPeriodType>('monthly');
    const [periodStartDay, setPeriodStartDay] = useState<number>(1);
    const [selectedCategoryIds, setSelectedCategoryIds] = useState<string[]>(
        [],
    );
    const [selectedLabelIds, setSelectedLabelIds] = useState<string[]>([]);
    const [allocatedAmount, setAllocatedAmount] = useState<number>(0);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const allCategories = (page.props.categories as Category[]) || [];
    const allLabels = (page.props.labels as Label[]) || [];

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setErrors({});

        const newErrors: Record<string, string> = {};

        if (
            selectedCategoryIds.length === 0 &&
            selectedLabelIds.length === 0
        ) {
            newErrors.selection = __(
                'You must select at least one category or label.'
            );
        }

        if (Object.keys(newErrors).length > 0) {
            setErrors(newErrors);
            return;
        }

        setIsSubmitting(true);

        router.post(
            store().url,
            {
                name,
                period_type: periodType,
                period_start_day: periodType === 'monthly' ? 1 : periodType,
                category_ids: selectedCategoryIds,
                label_ids: selectedLabelIds,
                allocated_amount: allocatedAmount,
            },
            {
                onSuccess: () => {
                    setOpen(false);
                    setName('');
                    setPeriodType('monthly');
                    setPeriodStartDay(1);
                    setSelectedCategoryIds([]);
                    setSelectedLabelIds([]);
                    setAllocatedAmount(0);
                    setErrors({});
                },
                onError: (errors) => {
                    setErrors(errors as Record<string, string>);
                },
                onFinish: () => setIsSubmitting(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>

        </Dialog>
    );
}