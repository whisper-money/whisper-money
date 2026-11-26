import { __ } from '@/utils/i18n';
import { Head } from '@inertiajs/react';

import AppearanceTabs from '@/components/appearance-tabs';
import HeadingSmall from '@/components/heading-small';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    CHART_COLOR_SCHEMES,
    useChartColorScheme,
} from '@/hooks/use-chart-color-scheme';
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

export default function Appearance() {
    const { scheme, saveScheme } = useChartColorScheme();

    const handleSchemeChange = (value: string) => {
        saveScheme(value as ChartColorScheme);
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
                            {CHART_COLOR_SCHEMES.map(({ value, label }) => (
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
