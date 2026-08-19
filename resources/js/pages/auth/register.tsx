import { login } from '@/routes';
import { store } from '@/routes/register';
import { __ } from '@/utils/i18n';
import { Form, Head } from '@inertiajs/react';
import { useCallback } from 'react';

import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PasswordInput } from '@/components/ui/password-input';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/auth-layout';
import { transactionSyncService } from '@/services/transaction-sync';

export default function Register({
    signupPlan,
}: {
    signupPlan?: string | null;
}) {
    const detectedTimezone =
        typeof window !== 'undefined'
            ? Intl.DateTimeFormat().resolvedOptions().timeZone || ''
            : '';

    const registrationForm = store.form();

    const handleBeforeSubmit = useCallback(async () => {
        await transactionSyncService.clearAll();
        return true;
    }, []);

    return (
        <AuthLayout
            title={__('Create an account')}
            description={__('Enter your details below to create your account')}
        >
            <Head title={__('Register')} />
            <Form
                {...registrationForm}
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
                onBefore={handleBeforeSubmit}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <input
                            type="hidden"
                            name="timezone"
                            value={detectedTimezone}
                        />

                        <InputError message={errors.timezone} />

                        {signupPlan && (
                            <input
                                type="hidden"
                                name="signup_plan"
                                value={signupPlan}
                            />
                        )}

                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="name">{__('Name')}</Label>
                                <Input
                                    id="name"
                                    type="text"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="name"
                                    name="name"
                                    placeholder={__('Full name')}
                                />

                                <InputError
                                    message={errors.name}
                                    className="mt-2"
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">
                                    {__('Email address')}
                                </Label>
                                <Input
                                    id="email"
                                    type="email"
                                    required
                                    tabIndex={2}
                                    autoComplete="email"
                                    name="email"
                                    placeholder={__('email@example.com')}
                                />

                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">
                                    {__('Password')}
                                </Label>
                                <PasswordInput
                                    id="password"
                                    required
                                    tabIndex={3}
                                    autoComplete="new-password"
                                    name="password"
                                    placeholder={__('Password')}
                                />

                                <InputError message={errors.password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password_confirmation">
                                    {__('Confirm password')}
                                </Label>
                                <PasswordInput
                                    id="password_confirmation"
                                    required
                                    tabIndex={4}
                                    autoComplete="new-password"
                                    name="password_confirmation"
                                    placeholder={__('Confirm password')}
                                />

                                <InputError
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <Button
                                type="submit"
                                className="mt-2 w-full"
                                tabIndex={5}
                                data-test="register-user-button"
                            >
                                {processing && <Spinner />}
                                {__('Create account')}
                            </Button>
                        </div>

                        <div className="text-center text-sm text-muted-foreground">
                            {__('Already have an account?')}{' '}
                            <TextLink href={login()} tabIndex={6}>
                                {__('Log in')}
                            </TextLink>
                        </div>
                    </>
                )}
            </Form>
        </AuthLayout>
    );
}
