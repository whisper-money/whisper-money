import { router } from '@inertiajs/react';
import {
    CircleCheckIcon,
    InfoIcon,
    Loader2Icon,
    OctagonXIcon,
    TriangleAlertIcon,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { Toaster } from 'sonner';

const isOnboardingPath = () =>
    typeof window !== 'undefined' &&
    window.location.pathname.startsWith('/onboarding');

/**
 * Onboarding toasts open at the top; everywhere else they are lifted off the
 * bottom to clear the mobile tab bar.
 *
 * The wizard has no tab bar, but every step pins its own action to the bottom
 * of the screen, and a toast down there lands on top of it: at 390px the
 * network toast covered "Let's go" outright and ate the tap — on the very
 * screen where a dropped request makes the user want to press it again. The
 * top is the only edge of a step that holds nothing but a progress bar, and it
 * stays right however tall a footer grows.
 */
export function AppToaster() {
    const [isOnboarding, setIsOnboarding] = useState(isOnboardingPath);

    useEffect(() => {
        return router.on('navigate', () => setIsOnboarding(isOnboardingPath()));
    }, []);

    return (
        <Toaster
            richColors
            position={isOnboarding ? 'top-center' : undefined}
            mobileOffset={isOnboarding ? undefined : { bottom: '110px' }}
            icons={{
                success: <CircleCheckIcon className="size-4" />,
                info: <InfoIcon className="size-4" />,
                warning: <TriangleAlertIcon className="size-4" />,
                error: <OctagonXIcon className="size-4" />,
                loading: <Loader2Icon className="size-4 animate-spin" />,
            }}
        />
    );
}
