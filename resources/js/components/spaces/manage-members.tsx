import { removeMember } from '@/actions/App/Http/Controllers/SpaceController';
import {
    store as inviteMember,
    destroy as revokeInvitation,
} from '@/actions/App/Http/Controllers/SpaceInvitationController';
import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { Mail, UserMinus } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface Member {
    id: string;
    name: string;
    email: string;
}

interface PendingInvitation {
    id: string;
    email: string;
}

export interface ManagedSpace {
    id: string;
    name: string;
    members: Member[];
    invitations: PendingInvitation[];
}

export function ManageMembers({
    managedSpaces,
    seatsInUse,
    maxSeats,
}: {
    managedSpaces: ManagedSpace[];
    seatsInUse: number;
    maxSeats: number;
}) {
    if (managedSpaces.length === 0) {
        return null;
    }

    const atCapacity = seatsInUse >= maxSeats;

    return (
        <div className="space-y-6">
            <HeadingSmall
                title={__('Members')}
                description={__(
                    'Invite people to your spaces. They can see and work with everything in the spaces they join.',
                )}
            />

            <p className="text-sm text-muted-foreground">
                {__(':used of :max seats used', {
                    used: String(seatsInUse),
                    max: String(maxSeats),
                })}
            </p>

            {managedSpaces.map((space) => (
                <SpaceMemberCard
                    key={space.id}
                    space={space}
                    atCapacity={atCapacity}
                />
            ))}
        </div>
    );
}

function SpaceMemberCard({
    space,
    atCapacity,
}: {
    space: ManagedSpace;
    atCapacity: boolean;
}) {
    const [email, setEmail] = useState('');
    const [processing, setProcessing] = useState(false);

    const invite = (event: FormEvent) => {
        event.preventDefault();
        setProcessing(true);
        router.post(
            inviteMember(space.id).url,
            { email },
            {
                preserveScroll: true,
                onFinish: () => setProcessing(false),
                onSuccess: () => setEmail(''),
            },
        );
    };

    return (
        <div className="rounded-lg border border-border p-4">
            <div className="font-medium">{space.name}</div>

            <ul className="mt-3 space-y-2">
                {space.members.map((member) => (
                    <li
                        key={member.id}
                        className="flex items-center justify-between gap-3 text-sm"
                        data-test="space-member"
                    >
                        <span>
                            {member.name}{' '}
                            <span className="text-muted-foreground">
                                {member.email}
                            </span>
                        </span>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="text-destructive hover:text-destructive"
                            onClick={() =>
                                router.delete(
                                    removeMember({
                                        space: space.id,
                                        member: member.id,
                                    }).url,
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <UserMinus className="size-4" />
                            {__('Remove')}
                        </Button>
                    </li>
                ))}

                {space.invitations.map((invitation) => (
                    <li
                        key={invitation.id}
                        className="flex items-center justify-between gap-3 text-sm text-muted-foreground"
                        data-test="space-pending-invitation"
                    >
                        <span className="inline-flex items-center gap-2">
                            <Mail className="size-4" />
                            {invitation.email} · {__('Pending')}
                        </span>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                router.delete(
                                    revokeInvitation({
                                        space: space.id,
                                        invitation: invitation.id,
                                    }).url,
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {__('Revoke')}
                        </Button>
                    </li>
                ))}

                {space.members.length === 0 &&
                    space.invitations.length === 0 && (
                        <li className="text-sm text-muted-foreground">
                            {__('No members yet.')}
                        </li>
                    )}
            </ul>

            <form
                onSubmit={invite}
                className="mt-4 flex flex-col gap-2 sm:flex-row"
            >
                <Input
                    type="email"
                    value={email}
                    onChange={(event) => setEmail(event.target.value)}
                    placeholder={__('name@example.com')}
                    disabled={atCapacity}
                    required
                />
                <Button
                    type="submit"
                    disabled={processing || atCapacity || email.trim() === ''}
                >
                    {__('Invite')}
                </Button>
            </form>
            {atCapacity && (
                <p className="mt-2 text-xs text-muted-foreground">
                    {__("You've reached your plan's seat limit.")}
                </p>
            )}
        </div>
    );
}
