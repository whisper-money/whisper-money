import HeadingSmall from '@/components/heading-small';
import TwoFactorRecoveryCodes from '@/components/two-factor-recovery-codes';
import TwoFactorSetupModal from '@/components/two-factor-setup-modal';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTwoFactorAuth } from '@/hooks/use-two-factor-auth';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { disable, enable, show } from '@/routes/two-factor';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { __ } from '@/utils/i18n';
import { Form, Head, usePage } from '@inertiajs/react';
import { InfoIcon, ShieldBan, ShieldCheck } from 'lucide-react';
import { useState } from 'react';

interface TwoFactorProps {
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Two-Factor Authentication',
        href: show.url(),
    },
];

export default function TwoFactor({
    requiresConfirmation = false,
    twoFactorEnabled = false,
}: TwoFactorProps) {
    const { auth } = usePage<SharedData>().props;
    const isDemoAccount = auth?.isDemoAccount ?? false;
    const {
        qrCodeSvg,
        hasSetupData,
        manualSetupKey,
        clearSetupData,
        fetchSetupData,
        recoveryCodesList,
        fetchRecoveryCodes,
        errors,
    } = useTwoFactorAuth();
    const [showSetupModal, setShowSetupModal] = useState<boolean>(false);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Two-Factor Authentication')} />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title={__('Two-Factor Authentication')}
                        description={__(
                            'Manage your two-factor authentication settings',
                        )}
                    />

                    {isDemoAccount && (
                        <Alert>
                            <InfoIcon className="h-4 w-4" />
                            <AlertDescription>
                                {__(
                                    'Two-factor authentication settings cannot be\n                                changed on the demo account.',
                                )}
                            </AlertDescription>
                        </Alert>
                    )}

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
                                errors={errors}
                            />

                            {!isDemoAccount && (
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
                            )}
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

                            {!isDemoAccount && (
                                <div>
                                    {hasSetupData ? (
                                        <Button
                                            onClick={() =>
                                                setShowSetupModal(true)
                                            }
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
                            )}
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
                        errors={errors}
                    />
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
