import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

/**
 * Hook to get the current locale from Inertia shared props
 */
export function useLocale(): string {
    return usePage<SharedData>().props.locale;
}
