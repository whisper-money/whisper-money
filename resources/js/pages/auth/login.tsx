import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PasswordInput } from '@/components/ui/password-input';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/auth-layout';
import { clearKey } from '@/lib/key-storage';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import { type SharedData } from '@/types';
import { __ } from '@/utils/i18n';
import { Form, Head, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface LoginProps {
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
}

export default function Login({
    status,
    canResetPassword,
    canRegister,
}: LoginProps) {
    const { demoCredentials } = usePage<SharedData>().props;
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');

    const forceRegistration =
        typeof window !== 'undefined' &&
        new URLSearchParams(window.location.search).get('force') === '1';

    useEffect(() => {
        clearKey();

        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('demo') === '1' && demoCredentials) {
            setEmail(demoCredentials.email);
            setPassword(demoCredentials.password);
        }
    }, [demoCredentials]);

    return (
        <AuthLayout
            title={__('Log in to your account')}
            description={__('Enter your email and password below to log in')}
        >
            <Head title={__('Log in')} />

            <Form
                {...store.form()}
                onSuccess={clearKey}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="email">
                                    {__('Email address')}
                                </Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="email"
                                    placeholder={__('email@example.com')}
                                    value={email}
                                    onChange={(e) => setEmail(e.target.value)}
                                />

                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <div className="flex items-center">
                                    <Label htmlFor="password">
                                        {__('Password')}
                                    </Label>
                                    {canResetPassword && (
                                        <TextLink
                                            href={request()}
                                            className="ml-auto text-sm"
                                            tabIndex={5}
                                        >
                                            {__('Forgot password?')}
                                        </TextLink>
                                    )}
                                </div>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    tabIndex={2}
                                    autoComplete="current-password"
                                    placeholder={__('Password')}
                                    value={password}
                                    onChange={(e) =>
                                        setPassword(e.target.value)
                                    }
                                />

                                <InputError message={errors.password} />
                            </div>

                            <div className="flex items-center space-x-3">
                                <Checkbox
                                    id="remember"
                                    name="remember"
                                    tabIndex={3}
                                />

                                <Label htmlFor="remember">
                                    {__('Remember me')}
                                </Label>
                            </div>

                            <Button
                                type="submit"
                                className="mt-4 w-full"
                                tabIndex={4}
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                {__('Log in')}
                            </Button>
                        </div>

                        {canRegister && (
                            <div className="text-center text-sm text-muted-foreground">
                                {__("Don't have an account?")}{' '}
                                <TextLink
                                    href={
                                        forceRegistration
                                            ? register({ query: { force: 1 } })
                                            : register()
                                    }
                                    tabIndex={5}
                                >
                                    {__('Sign up')}
                                </TextLink>
                            </div>
                        )}
                    </>
                )}
            </Form>

            {status && (
                <div className="mb-4 text-center text-sm font-medium text-green-600">
                    {status}
                </div>
            )}
        </AuthLayout>
    );
}
