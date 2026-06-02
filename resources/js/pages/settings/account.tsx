import PasswordController from '@/actions/App/Http/Controllers/Settings/PasswordController';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import TwoFactorRecoveryCodes from '@/components/two-factor-recovery-codes';
import TwoFactorSetupModal from '@/components/two-factor-setup-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { useTwoFactorAuth } from '@/hooks/use-two-factor-auth';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { edit as accountEdit } from '@/routes/account';
import { disable, enable } from '@/routes/two-factor';
import { send } from '@/routes/verification';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { LANGUAGE_OPTIONS } from '@/types/language';
import { __ } from '@/utils/i18n';
import { Transition } from '@headlessui/react';
import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { ShieldBan, ShieldCheck } from 'lucide-react';
import { useRef, useState } from 'react';

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
    notifyOnBankTransactionsSynced = true,
}: {
    mustVerifyEmail: boolean;
    status?: string;
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
    notifyOnBankTransactionsSynced?: boolean;
}) {
    const { auth, currencies } = usePage<SharedData>().props;
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);
    const [notifyBankTransactions, setNotifyBankTransactions] = useState(
        notifyOnBankTransactionsSynced,
    );

    const handleNotifyBankTransactionsChange = (checked: boolean) => {
        setNotifyBankTransactions(checked);

        router.patch(
            '/settings/notifications',
            { notifications: { bank_transactions_synced: checked } },
            { preserveScroll: true, preserveState: true },
        );
    };

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

                                <div className="grid gap-2">
                                    <Label htmlFor="currency_code">
                                        {__('Currency')}
                                    </Label>

                                    <Select
                                        name="currency_code"
                                        defaultValue={auth.user.currency_code}
                                        required
                                    >
                                        <SelectTrigger className="mt-1 w-full">
                                            <SelectValue
                                                placeholder={__(
                                                    'Select currency',
                                                )}
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {currencies.profile.map(
                                                (currency) => (
                                                    <SelectItem
                                                        key={currency.code}
                                                        value={currency.code}
                                                    >
                                                        {currency.code} -{' '}
                                                        {currency.name}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>

                                    <InputError
                                        className="mt-2"
                                        message={errors.currency_code}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="locale">
                                        {__('Language')}
                                    </Label>

                                    <Select
                                        name="locale"
                                        defaultValue={
                                            auth.user.locale ?? undefined
                                        }
                                    >
                                        <SelectTrigger className="mt-1 w-full">
                                            <SelectValue
                                                placeholder={__(
                                                    'Select language',
                                                )}
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {LANGUAGE_OPTIONS.map(
                                                (language) => (
                                                    <SelectItem
                                                        key={language.code}
                                                        value={language.code}
                                                    >
                                                        {language.label}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>

                                    <InputError
                                        className="mt-2"
                                        message={errors.locale}
                                    />
                                </div>

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
                        title={__('Notifications')}
                        description={__(
                            'Manage the automatic notifications you receive',
                        )}
                    />

                    <div className="flex items-start gap-3">
                        <Checkbox
                            id="notify-on-bank-transactions-synced"
                            checked={notifyBankTransactions}
                            onCheckedChange={(checked) =>
                                handleNotifyBankTransactionsChange(
                                    checked === true,
                                )
                            }
                            className="mt-0.5"
                        />
                        <div className="grid gap-1">
                            <Label htmlFor="notify-on-bank-transactions-synced">
                                {__('New transactions from connected banks')}
                            </Label>
                            <p className="text-sm text-muted-foreground">
                                {__(
                                    'Receive an email when new transactions are imported from your connected banks. Sent at most once a day.',
                                )}
                            </p>
                        </div>
                    </div>
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

                                    <Input
                                        id="current_password"
                                        ref={currentPasswordInput}
                                        name="current_password"
                                        type="password"
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

                                    <Input
                                        id="password"
                                        ref={passwordInput}
                                        name="password"
                                        type="password"
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

                                    <Input
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        type="password"
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
