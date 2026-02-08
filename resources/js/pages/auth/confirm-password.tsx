import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/auth-layout';
import { store } from '@/routes/password/confirm';
import { __ } from '@/utils/i18n';
import { Form, Head } from '@inertiajs/react';

export default function ConfirmPassword() {
    return (
        <AuthLayout
            title={__('Confirm your password')}
            description={__(
                'This is a secure area of the application. Please confirm your password before continuing.',
            )}
        >
            <Head title={__('Confirm password')} />

            <Form {...store.form()} resetOnSuccess={['password']}>
                {({ processing, errors }) => (
                    <div className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="password">{__('Password')}</Label>
                            <Input
                                id="password"
                                type="password"
                                name="password"
                                placeholder={__('Password')}
                                autoComplete="current-password"
                                autoFocus
                            />

                            <InputError message={errors.password} />
                        </div>

                        <div className="flex items-center">
                            <Button
                                className="w-full"
                                disabled={processing}
                                data-test="confirm-password-button"
                            >
                                {processing && <Spinner />}
                                {__('Confirm password')}
                            </Button>
                        </div>
                    </div>
                )}
            </Form>
        </AuthLayout>
    );
}
