import PasswordController from '@/actions/App/Http/Controllers/Settings/PasswordController';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import TwoFactorRecoveryCodes from '@/components/two-factor-recovery-codes';
import TwoFactorSetupModal from '@/components/two-factor-setup-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PasswordInput } from '@/components/ui/password-input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { useLocale } from '@/hooks/use-locale';
import { useTwoFactorAuth } from '@/hooks/use-two-factor-auth';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { edit as accountEdit } from '@/routes/account';
import { disable, enable } from '@/routes/two-factor';
import { send } from '@/routes/verification';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { LANGUAGE_OPTIONS } from '@/types/language';
import { formatLocaleOptions } from '@/utils/format-locale';
import { __ } from '@/utils/i18n';
import { Transition } from '@headlessui/react';
import { Form, Head, Link, usePage } from '@inertiajs/react';
import { ShieldBan, ShieldCheck } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

/**
 * One labelled dropdown in the profile form. Three of these sit in a row —
 * currency, language, region — and they were the same twenty-five lines three
 * times over until they were not.
 */
function PreferenceSelect({
    name,
    label,
    placeholder,
    defaultValue,
    options,
    error,
    required = false,
}: {
    name: string;
    label: string;
    placeholder: string;
    defaultValue?: string;
    options: readonly { code: string; label: string }[];
    error?: string;
    required?: boolean;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={name}>{label}</Label>

            <Select name={name} defaultValue={defaultValue} required={required}>
                <SelectTrigger className="mt-1 w-full">
                    <SelectValue placeholder={placeholder} />
                </SelectTrigger>
                <SelectContent>
                    {options.map((option) => (
                        <SelectItem key={option.code} value={option.code}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            <InputError className="mt-2" message={error} />
        </div>
    );
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Bank accounts',
        href: accountEdit().url,
    },
];

export default function Account({
    mustVerifyEmail,
    status,
    requiresConfirmation = false,
    twoFactorEnabled = false,
}: {
    mustVerifyEmail: boolean;
    status?: string;
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
}) {
    const { auth, currencies, formatLocales } = usePage<SharedData>().props;
    const locale = useLocale();
    // Each row carries a live example, so a reader picks by what it does to
    // their money rather than by guessing what a tag means.
    const formatOptions = useMemo(
        () =>
            formatLocaleOptions(formatLocales, auth.user.currency_code, locale),
        [formatLocales, auth.user.currency_code, locale],
    );
    const currencyOptions = useMemo(
        () =>
            currencies.profile.map((currency) => ({
                code: currency.code,
                label: `${currency.code} - ${currency.name}`,
            })),
        [currencies.profile],
    );
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    const {
        qrCodeSvg,
        hasSetupData,
        manualSetupKey,
        clearSetupData,
        fetchSetupData,
        recoveryCodesList,
        fetchRecoveryCodes,
        errors: twoFactorErrors,
    } = useTwoFactorAuth();
    const [showSetupModal, setShowSetupModal] = useState<boolean>(false);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Bank accounts')} />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title={__('Profile information')}
                        description={__('Update your name and email address')}
                    />

                    <Form
                        {...ProfileController.update.form()}
                        options={{
                            preserveScroll: true,
                        }}
                        className="space-y-6"
                    >
                        {({ processing, recentlySuccessful, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="name">{__('Name')}</Label>

                                    <Input
                                        id="name"
                                        className="mt-1 block w-full"
                                        defaultValue={auth.user.name}
                                        name="name"
                                        required
                                        autoComplete="name"
                                        placeholder={__('Name')}
                                    />

                                    <InputError
                                        className="mt-2"
                                        message={errors.name}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="email">
                                        {__('Email address')}
                                    </Label>

                                    <Input
                                        id="email"
                                        type="email"
                                        className="mt-1 block w-full"
                                        defaultValue={auth.user.email}
                                        name="email"
                                        required
                                        autoComplete="username"
                                        placeholder={__('Email address')}
                                    />

                                    <InputError
                                        className="mt-2"
                                        message={errors.email}
                                    />
                                </div>

                                <PreferenceSelect
                                    name="currency_code"
                                    label={__('Currency')}
                                    placeholder={__('Select currency')}
                                    defaultValue={auth.user.currency_code}
                                    options={currencyOptions}
                                    error={errors.currency_code}
                                    required
                                />

                                <PreferenceSelect
                                    name="locale"
                                    label={__('Language')}
                                    placeholder={__('Select language')}
                                    defaultValue={auth.user.locale ?? undefined}
                                    options={LANGUAGE_OPTIONS}
                                    error={errors.locale}
                                />

                                <PreferenceSelect
                                    name="format_locale"
                                    label={__('Number and date format')}
                                    placeholder={__('Select a region')}
                                    defaultValue={
                                        auth.user.format_locale ?? undefined
                                    }
                                    options={formatOptions}
                                    error={errors.format_locale}
                                />

                                {mustVerifyEmail &&
                                    auth.user.email_verified_at === null && (
                                        <div>
                                            <p className="-mt-4 text-sm text-muted-foreground">
                                                {__(
                                                    'Your email address is unverified.',
                                                )}{' '}
                                                <Link
                                                    href={send()}
                                                    as="button"
                                                    className="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                                                >
                                                    {__(
                                                        'Click here to resend the\n                                                    verification email.',
                                                    )}
                                                </Link>
                                            </p>

                                            {status ===
                                                'verification-link-sent' && (
                                                <div className="mt-2 text-sm font-medium text-green-600">
                                                    {__(
                                                        'A new verification link has\n                                                    been sent to your email\n                                                    address.',
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    )}

                                <div className="flex items-center gap-4">
                                    <Button
                                        disabled={processing}
                                        data-test="update-profile-button"
                                    >
                                        {__('Save')}
                                    </Button>

                                    <Transition
                                        show={recentlySuccessful}
                                        enter="transition ease-in-out"
                                        enterFrom="opacity-0"
                                        leave="transition ease-in-out"
                                        leaveTo="opacity-0"
                                    >
                                        <p className="text-sm text-neutral-600">
                                            {__('Saved')}
                                        </p>
                                    </Transition>
                                </div>
                            </>
                        )}
                    </Form>
                </div>

                <Separator />

                <div className="space-y-6">
                    <HeadingSmall
                        title={__('Update password')}
                        description={__(
                            'Ensure your account is using a long, random password to stay secure.',
                        )}
                    />

                    <Form
                        {...PasswordController.update.form()}
                        options={{
                            preserveScroll: true,
                        }}
                        resetOnError={[
                            'password',
                            'password_confirmation',
                            'current_password',
                        ]}
                        resetOnSuccess
                        onError={(errors) => {
                            if (errors.password) {
                                passwordInput.current?.focus();
                            }

                            if (errors.current_password) {
                                currentPasswordInput.current?.focus();
                            }
                        }}
                        className="space-y-6"
                    >
                        {({ errors, processing, recentlySuccessful }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="current_password">
                                        {__('Current password')}
                                    </Label>

                                    <PasswordInput
                                        id="current_password"
                                        ref={currentPasswordInput}
                                        name="current_password"
                                        className="mt-1 block w-full"
                                        autoComplete="current-password"
                                        placeholder={__('Current password')}
                                    />

                                    <InputError
                                        message={errors.current_password}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="password">
                                        {__('New password')}
                                    </Label>

                                    <PasswordInput
                                        id="password"
                                        ref={passwordInput}
                                        name="password"
                                        className="mt-1 block w-full"
                                        autoComplete="new-password"
                                        placeholder={__('New password')}
                                    />

                                    <InputError message={errors.password} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="password_confirmation">
                                        {__('Confirm password')}
                                    </Label>

                                    <PasswordInput
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        className="mt-1 block w-full"
                                        autoComplete="new-password"
                                        placeholder={__('Confirm password')}
                                    />

                                    <InputError
                                        message={errors.password_confirmation}
                                    />
                                </div>

                                <div className="flex items-center gap-4">
                                    <Button
                                        disabled={processing}
                                        data-test="update-password-button"
                                    >
                                        {__('Save password')}
                                    </Button>

                                    <Transition
                                        show={recentlySuccessful}
                                        enter="transition ease-in-out"
                                        enterFrom="opacity-0"
                                        leave="transition ease-in-out"
                                        leaveTo="opacity-0"
                                    >
                                        <p className="text-sm text-neutral-600">
                                            {__('Saved')}
                                        </p>
                                    </Transition>
                                </div>
                            </>
                        )}
                    </Form>
                </div>

                <Separator />

                <div className="space-y-6">
                    <HeadingSmall
                        title={__('Two-Factor Authentication')}
                        description={__(
                            'Manage your two-factor authentication settings',
                        )}
                    />

                    {twoFactorEnabled ? (
                        <div className="flex flex-col items-start justify-start space-y-4">
                            <Badge variant="default">{__('Enabled')}</Badge>
                            <p className="text-muted-foreground">
                                {__(
                                    'With two-factor authentication enabled, you will\n                                be prompted for a secure, random pin during\n                                login, which you can retrieve from the\n                                TOTP-supported application on your phone.',
                                )}
                            </p>

                            <TwoFactorRecoveryCodes
                                recoveryCodesList={recoveryCodesList}
                                fetchRecoveryCodes={fetchRecoveryCodes}
                                errors={twoFactorErrors}
                            />

                            <div className="relative inline">
                                <Form {...disable.form()}>
                                    {({ processing }) => (
                                        <Button
                                            variant="destructive"
                                            type="submit"
                                            disabled={processing}
                                        >
                                            <ShieldBan />
                                            {__('Disable 2FA')}
                                        </Button>
                                    )}
                                </Form>
                            </div>
                        </div>
                    ) : (
                        <div className="flex flex-col items-start justify-start space-y-4">
                            <Badge variant="destructive">
                                {__('Disabled')}
                            </Badge>
                            <p className="text-muted-foreground">
                                {__(
                                    'When you enable two-factor authentication, you\n                                will be prompted for a secure pin during login.\n                                This pin can be retrieved from a TOTP-supported\n                                application on your phone.',
                                )}
                            </p>

                            <div>
                                {hasSetupData ? (
                                    <Button
                                        onClick={() => setShowSetupModal(true)}
                                    >
                                        <ShieldCheck />
                                        {__('Continue Setup')}
                                    </Button>
                                ) : (
                                    <Form
                                        {...enable.form()}
                                        onSuccess={() =>
                                            setShowSetupModal(true)
                                        }
                                    >
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                <ShieldCheck />
                                                {__('Enable 2FA')}
                                            </Button>
                                        )}
                                    </Form>
                                )}
                            </div>
                        </div>
                    )}

                    <TwoFactorSetupModal
                        isOpen={showSetupModal}
                        onClose={() => setShowSetupModal(false)}
                        requiresConfirmation={requiresConfirmation}
                        twoFactorEnabled={twoFactorEnabled}
                        qrCodeSvg={qrCodeSvg}
                        manualSetupKey={manualSetupKey}
                        clearSetupData={clearSetupData}
                        fetchSetupData={fetchSetupData}
                        errors={twoFactorErrors}
                    />
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
