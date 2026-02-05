import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/auth-layout';
import { clearKey } from '@/lib/key-storage';
import { logout } from '@/routes';
import { send } from '@/routes/verification';
import { __ } from '@/utils/i18n';
import { Form, Head, Link } from '@inertiajs/react';

export default function VerifyEmail({ status }: { status?: string }) {
    const handleLogout = () => {
        clearKey();
    };

    return (
        <AuthLayout
            title={__('Verify email')}
            description={__(
                'Please verify your email address by clicking on the link we just emailed to you.',
            )}
        >
            <Head title={__('Email verification')} />

            {status === 'verification-link-sent' && (
                <div className="mb-4 text-center text-sm font-medium text-green-600">
                    {__(
                        'A new verification link has been sent to the email address you provided during registration.',
                    )}
                </div>
            )}

            <Form {...send.form()} className="space-y-6 text-center">
                {({ processing }) => (
                    <>
                        <Button disabled={processing} variant="secondary">
                            {processing && <Spinner />}
                            {__('Resend verification email')}
                        </Button>

                        <Link
                            href={logout()}
                            as="button"
                            onClick={handleLogout}
                            className="mx-auto block text-sm text-blue-600 underline hover:text-blue-800"
                        >
                            {__('Log out')}
                        </Link>
                    </>
                )}
            </Form>
        </AuthLayout>
    );
}
