import { StepButton } from '@/components/onboarding/step-button';
import { StepList, StepRow } from '@/components/onboarding/step-list';
import { StepNote, StepScreen } from '@/components/onboarding/step-screen';
import { __ } from '@/utils/i18n';
import { Head } from '@inertiajs/react';
import { Check, RefreshCw, RotateCcw } from 'lucide-react';

interface ConnectionCompleteProps {
    status: 'success' | 'error';
    message: string;
    bank?: string | null;
}

/**
 * Where an iOS PWA's bank redirect lands: Safari gets the return URL, the app
 * session does not exist there, and `AuthorizationController::finishRedirect`
 * has nowhere behind auth to send the user.
 *
 * The connection itself is already finished server-side by the time this
 * renders, so the page's whole job is to say so and give the user a way back —
 * which until now it did not have, not even a link.
 */
export default function ConnectionComplete({
    status,
    message,
    bank = null,
}: ConnectionCompleteProps) {
    const isSuccess = status === 'success';

    const title = isSuccess
        ? bank
            ? __(':bank sent you here', { bank })
            : __('Your bank sent you here')
        : __('Connection unsuccessful');

    return (
        <div className="flex min-h-svh flex-col bg-background">
            <Head title={title} />

            {/* The bank opens this page in a browser of its own choosing, so it
                gets none of the onboarding chrome — including the header that
                would otherwise keep the title off the top edge. */}
            <div className="pt-safe min-h-14 shrink-0 md:min-h-18" />

            <StepScreen
                title={title}
                description={
                    isSuccess
                        ? __(
                              'Your bank opened this in a different browser than the one you started in, so we can’t see your session from here.',
                          )
                        : message
                }
                footer={
                    <>
                        <StepButton text={__('Open Whisper')} href="/" />
                        <StepNote>
                            {isSuccess
                                ? __('Same account, same progress.')
                                : __(
                                      'Nothing was created, and nothing was charged.',
                                  )}
                        </StepNote>
                    </>
                }
            >
                {isSuccess && (
                    <>
                        <StepList>
                            <StepRow
                                icon={Check}
                                title={__('The connection worked')}
                                description={
                                    bank
                                        ? __(
                                              ':bank confirmed it. Nothing to redo.',
                                              { bank },
                                          )
                                        : __(
                                              'Your bank confirmed it. Nothing to redo.',
                                          )
                                }
                            />
                            <StepRow
                                icon={RefreshCw}
                                title={__(
                                    'Your history is already downloading',
                                )}
                                description={__(
                                    'It keeps going in the background, whether this tab is open or not.',
                                )}
                            />
                        </StepList>

                        <p className="rounded-lg bg-muted px-4.5 py-4 text-sm leading-normal text-pretty text-muted-foreground">
                            {__(
                                'Go back to the Whisper tab or app you started in — everything is there, already moving. This tab can be closed.',
                            )}
                        </p>
                    </>
                )}

                {!isSuccess && (
                    <StepList>
                        <StepRow
                            icon={RotateCcw}
                            title={__('You can try again')}
                            description={__(
                                'Open Whisper in the tab or app you started in and connect your bank from there.',
                            )}
                        />
                    </StepList>
                )}
            </StepScreen>
        </div>
    );
}
