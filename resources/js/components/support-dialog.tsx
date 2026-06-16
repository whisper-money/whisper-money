import DiscordIcon from '@/components/icons/DiscordIcon';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { type SharedData, type User } from '@/types';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import { Mail } from 'lucide-react';

const DISCORD_URL = 'https://discord.gg/m8hUhx6D9D';
const SUPPORT_EMAIL = 'support@whisper.money';

function buildSupportMailto(user: User, version: string): string {
    const userAgent =
        typeof navigator !== 'undefined' ? navigator.userAgent : 'unknown';
    const page =
        typeof window !== 'undefined' ? window.location.href : 'unknown';

    const body = [
        __(
            'Please describe the problem and what you were doing when it happened. Keep the details below — they help us debug.',
        ),
        '',
        '',
        '--- Diagnostics (please keep) ---',
        `User ID: ${user.id}`,
        `App version: ${version}`,
        `Locale: ${user.locale ?? 'en'}`,
        `Page: ${page}`,
        `Browser: ${userAgent}`,
    ].join('\n');

    const query = `subject=${encodeURIComponent(__('Support request'))}&body=${encodeURIComponent(body)}`;

    return `mailto:${SUPPORT_EMAIL}?${query}`;
}

interface SupportDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    user: User;
}

export function SupportDialog({
    open,
    onOpenChange,
    user,
}: SupportDialogProps) {
    const { version } = usePage<SharedData>().props;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{__('Need help?')}</DialogTitle>
                    <DialogDescription>
                        {__(
                            'Join our community on Discord — the fastest way to get help. Prefer email? Write to us instead.',
                        )}
                    </DialogDescription>
                </DialogHeader>
                <div className="flex flex-col gap-3">
                    <Button asChild>
                        <a
                            href={DISCORD_URL}
                            target="_blank"
                            rel="noopener noreferrer"
                            onClick={() => onOpenChange(false)}
                        >
                            <DiscordIcon className="size-4" />
                            {__('Join the community')}
                        </a>
                    </Button>
                    <Button variant="outline" asChild>
                        <a
                            href={buildSupportMailto(user, version)}
                            onClick={() => onOpenChange(false)}
                        >
                            <Mail className="size-4" />
                            {__('Email support')}
                        </a>
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
