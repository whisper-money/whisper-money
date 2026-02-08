import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/auth-layout';
import { dashboard } from '@/routes';
import { __ } from '@/utils/i18n';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

export default function Success() {
    const [loading, setLoading] = useState(true);

    // Simulate loading for 5 seconds
    setTimeout(() => {
        setLoading(false);
    }, 3000);

    return (
        <AuthLayout
            title={
                loading ? __('Creating subscription...') : __('Welcome to Pro!')
            }
            description={
                loading
                    ? __('We are processing your payment...')
                    : __('Your subscription is now active')
            }
        >
            <Head title={__('Welcome to Pro!')} />

            <div className="flex flex-col items-center gap-6">
                {!loading && (
                    <p className="text-center text-muted-foreground">
                        {__(
                            'You now have full access to all Whisper Money features. Thank you for supporting us!',
                        )}
                    </p>
                )}

                <Link href={dashboard()} className="w-full">
                    <Button size="lg" disabled={loading} className="w-full">
                        {loading ? (
                            <>
                                <Spinner />
                                {__('Setting things up...')}
                            </>
                        ) : (
                            __('Go to Dashboard')
                        )}
                    </Button>
                </Link>
            </div>
        </AuthLayout>
    );
}
