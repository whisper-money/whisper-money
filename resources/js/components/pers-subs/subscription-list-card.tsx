import { PersonalSubscription } from "@/types/personal-subscriptions";
import { useLocale } from "@/hooks/use-locale";
import { useMemo } from "react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "../ui/card";
import { Link } from "lucide-react";
import { formatDate } from "@/utils/date";
import { __ } from "@/utils/i18n";

interface Props {
    subscription: PersonalSubscription,
    currencyCode: string;
}

export function SubscriptionListCard({ subscription, currencyCode }: Props) {
    const locale = useLocale();
    const currentPeriod = subscription.next_billing_date?.[0];

    const periodLabel = useMemo(() => {
        if (!currentPeriod) return __('No active period');

        const start = formatDate(currentPeriod, 'MMM D', locale);

        return `${start}`;
    }, [currentPeriod, locale]);

    const trackingNames = useMemo(() => {
        return [
            ...(subscription.categories?.map((category) => category.name) ?? []),
            ...(subscription.labels?.map((label) => label.name) ?? [])
        ];
    }, [subscription]);

    return (
        <Card>
            <CardHeader>
                <div className="flex items-start justify-between">
                    <div className="space-y-1">
                        <CardTitle className="text-xl">
                            <Link
                                className="-my-1 -ml-1.5 inline-flex items-center rounded-md px-1.5 py-1 transition-colors hover:bg-muted"
                            >
                                {subscription.name}
                            </Link>
                        </CardTitle>
                        <CardDescription className="flex items-center gap-2">
                            { }
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>
        </Card>
    )
}