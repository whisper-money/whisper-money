import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { PasswordInput } from '@/components/ui/password-input';
import { billing } from '@/routes/settings';
import { type SharedData } from '@/types';
import { __ } from '@/utils/i18n';
import { Form, Link, usePage } from '@inertiajs/react';
import { InfoIcon } from 'lucide-react';
import { useRef } from 'react';

export default function DeleteUser({
    hasActiveSubscriptionOrTrial,
}: {
    hasActiveSubscriptionOrTrial: boolean;
}) {
    const { auth } = usePage<SharedData>().props;
    const isDemoAccount = auth?.isDemoAccount ?? false;
    const passwordInput = useRef<HTMLInputElement>(null);

    if (isDemoAccount) {
        return (
            <div className="space-y-6">
                <HeadingSmall
                    title={__('Delete account')}
                    description={__(
                        'Mark your account as deleted and disable access',
                    )}
                />

                <Alert>
                    <InfoIcon className="h-4 w-4" />
                    <AlertDescription>
                        {__('The demo account cannot be deleted.')}
                    </AlertDescription>
                </Alert>
            </div>
        );
    }

    if (hasActiveSubscriptionOrTrial) {
        return (
            <div className="space-y-6">
                <HeadingSmall
                    title={__('Delete account')}
                    description={__(
                        'Mark your account as deleted and disable access',
                    )}
                />

                <Alert>
                    <InfoIcon className="h-4 w-4" />
                    <AlertDescription className="flex flex-col items-start gap-3">
                        <span>
                            {__(
                                'Please cancel your subscription before deleting your account.',
                            )}
                        </span>
                        <Button variant="secondary" asChild>
                            <Link href={billing.url()}>
                                {__('Manage billing')}
                            </Link>
                        </Button>
                    </AlertDescription>
                </Alert>
            </div>
        );
    }

    return (
        <div className="space-y-6">
            <HeadingSmall
                title={__('Delete account')}
                description={__(
                    'Mark your account as deleted and disable access',
                )}
            />

            <div className="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                <div className="relative space-y-0.5 text-red-600 dark:text-red-100">
                    <p className="font-medium">{__('Warning')}</p>
                    <p className="text-sm">
                        {__(
                            'Please proceed with caution, this cannot be undone.',
                        )}
                    </p>
                </div>

                <Dialog>
                    <DialogTrigger asChild>
                        <Button
                            variant="destructive"
                            data-test="delete-user-button"
                        >
                            {__('Delete account')}
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogTitle>
                            {__(
                                'Are you sure you want to delete your account?',
                            )}
                        </DialogTitle>
                        <DialogDescription>
                            {__(
                                'Once your account is deleted, you will be signed out\n                            and your access will be disabled. Your data will\n                            remain in the database. Please enter your password\n                            to confirm you would like to mark your account as\n                            deleted.',
                            )}
                        </DialogDescription>

                        <Form
                            {...ProfileController.destroy.form()}
                            options={{
                                preserveScroll: true,
                            }}
                            onError={() => passwordInput.current?.focus()}
                            resetOnSuccess
                            className="space-y-6"
                        >
                            {({ resetAndClearErrors, processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label
                                            htmlFor="password"
                                            className="sr-only"
                                        >
                                            {__('Password')}
                                        </Label>

                                        <PasswordInput
                                            id="password"
                                            name="password"
                                            ref={passwordInput}
                                            placeholder={__('Password')}
                                            autoComplete="current-password"
                                        />

                                        <InputError message={errors.password} />
                                    </div>

                                    <DialogFooter className="gap-2">
                                        <DialogClose asChild>
                                            <Button
                                                variant="secondary"
                                                onClick={() =>
                                                    resetAndClearErrors()
                                                }
                                            >
                                                {__('Cancel')}
                                            </Button>
                                        </DialogClose>

                                        <Button
                                            variant="destructive"
                                            disabled={processing}
                                            asChild
                                        >
                                            <button
                                                type="submit"
                                                data-test="confirm-delete-user-button"
                                            >
                                                {__('Delete account')}
                                            </button>
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </div>
    );
}
