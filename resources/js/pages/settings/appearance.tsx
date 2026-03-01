import { __ } from '@/utils/i18n';
import { Head, router } from '@inertiajs/react';

import AppearanceTabs from '@/components/appearance-tabs';
import HeadingSmall from '@/components/heading-small';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useChartColorScheme } from '@/hooks/use-chart-color-scheme';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { edit as editAppearance } from '@/routes/appearance';
import { type BreadcrumbItem, type ChartColorScheme } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Appearance settings',
        href: editAppearance().url,
    },
];

const schemes: { value: ChartColorScheme; label: string }[] = [
    { value: 'colorful', label: 'Colorful' },
    { value: 'neutral', label: 'Neutral' },
    { value: 'blue', label: 'Blue' },
    { value: 'pink', label: 'Pink' },
];

export default function Appearance() {
    const { scheme, updateScheme } = useChartColorScheme();

    const handleSchemeChange = (value: string) => {
        const newScheme = value as ChartColorScheme;
        updateScheme(newScheme);

        router.patch(
            '/settings/chart-color-scheme',
            { chart_color_scheme: newScheme },
            { preserveScroll: true },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Appearance settings')} />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title={__('Appearance settings')}
                        description={__(
                            "Update your account's appearance settings",
                        )}
                    />

                    <AppearanceTabs />
                </div>

                <div className="space-y-6">
                    <HeadingSmall
                        title={__('Chart color scheme')}
                        description={__(
                            'Choose the color palette for your charts',
                        )}
                    />
                    <Select value={scheme} onValueChange={handleSchemeChange}>
                        <SelectTrigger className="w-48">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {schemes.map(({ value, label }) => (
                                <SelectItem key={value} value={value}>
                                    {__(label)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
