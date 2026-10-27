import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';

/**
 * Shown to accounts without a Pro plan on a settings screen whose feature
 * only works on a paid plan.
 */
export function ProUpsellAlert({
    subscribeUrl,
    description,
}: {
    subscribeUrl: string;
    description: string;
}) {
    return (
        <Alert>
            <AlertTitle>{__('This is a Pro feature')}</AlertTitle>
            <AlertDescription>
                {description}{' '}
                <Link href={subscribeUrl} className="font-medium underline">
                    {__('Upgrade your account')}
                </Link>
            </AlertDescription>
        </Alert>
    );
}
