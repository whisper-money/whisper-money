import {
    data,
    store,
    vote,
} from '@/actions/App/Http/Controllers/IntegrationRequestController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { getCsrfToken } from '@/lib/csrf';
import { __ } from '@/utils/i18n';
import { ChevronUp } from 'lucide-react';
import { FormEvent, useEffect, useState } from 'react';

export interface IntegrationRequestItem {
    id: string;
    name: string;
    url: string;
    status: 'pending' | 'approved' | 'rejected';
    votes_count: number;
    has_voted: boolean;
    created_at: string;
}

interface BoardPayload {
    requests: IntegrationRequestItem[];
    actionsRemaining: number;
}

interface Props {
    initialRequests?: IntegrationRequestItem[];
    initialActionsRemaining?: number;
}

async function sendJson(
    url: string,
    method: 'GET' | 'POST',
    body?: Record<string, string>,
): Promise<Response> {
    return fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': getCsrfToken(),
        },
        body: body ? JSON.stringify(body) : undefined,
    });
}

export function IntegrationRequestsBoard({
    initialRequests,
    initialActionsRemaining,
}: Props) {
    const [requests, setRequests] = useState<IntegrationRequestItem[] | null>(
        initialRequests ?? null,
    );
    const [actionsRemaining, setActionsRemaining] = useState<number>(
        initialActionsRemaining ?? 0,
    );
    const [name, setName] = useState('');
    const [url, setUrl] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [showForm, setShowForm] = useState(false);

    useEffect(() => {
        if (initialRequests !== undefined) {
            return;
        }

        void (async () => {
            const response = await sendJson(data().url, 'GET');
            if (response.ok) {
                const payload: BoardPayload = await response.json();
                setRequests(payload.requests);
                setActionsRemaining(payload.actionsRemaining);
            }
        })();
    }, [initialRequests]);

    const apply = (payload: BoardPayload) => {
        setRequests(payload.requests);
        setActionsRemaining(payload.actionsRemaining);
    };

    const handleSubmit = async (event: FormEvent) => {
        event.preventDefault();
        setError(null);
        setBusy(true);

        try {
            const response = await sendJson(store().url, 'POST', { name, url });

            if (response.ok) {
                apply(await response.json());
                setName('');
                setUrl('');
                setShowForm(false);
                return;
            }

            const body = await response.json();
            setError(body.message ?? __('Something went wrong.'));
        } finally {
            setBusy(false);
        }
    };

    const handleVote = async (item: IntegrationRequestItem) => {
        if (busy || (!item.has_voted && actionsRemaining <= 0)) {
            return;
        }

        setError(null);
        setBusy(true);

        try {
            const response = await sendJson(vote(item.id).url, 'POST');

            if (response.ok) {
                apply(await response.json());
                return;
            }

            const body = await response.json();
            setError(body.message ?? __('Something went wrong.'));
        } finally {
            setBusy(false);
        }
    };

    const outOfActions = actionsRemaining <= 0;
    // A new request also auto-votes it, so it costs two actions.
    const cannotRequest = actionsRemaining < 2;

    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between gap-3">
                <p className="text-sm text-muted-foreground">
                    {__('You have :count actions left this month.', {
                        count: actionsRemaining,
                    })}
                </p>
                {!showForm && (
                    <Button
                        onClick={() => setShowForm(true)}
                        disabled={cannotRequest}
                    >
                        {__('Request integration')}
                    </Button>
                )}
            </div>

            {showForm && (
                <form
                    onSubmit={handleSubmit}
                    className="space-y-3 rounded-lg border p-4"
                >
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="integration-name">
                                {__('Name')}
                            </Label>
                            <Input
                                id="integration-name"
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                                placeholder={__('e.g. Revolut')}
                                maxLength={255}
                                required
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="integration-url">
                                {__('Link')}
                            </Label>
                            <Input
                                id="integration-url"
                                type="url"
                                value={url}
                                onChange={(e) => setUrl(e.target.value)}
                                placeholder="https://..."
                                maxLength={2048}
                                required
                            />
                        </div>
                    </div>
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            disabled={busy}
                            onClick={() => {
                                setShowForm(false);
                                setError(null);
                            }}
                        >
                            {__('Cancel')}
                        </Button>
                        <Button type="submit" disabled={busy || cannotRequest}>
                            {__('Submit')}
                        </Button>
                    </div>
                    <InputError message={error ?? undefined} />
                </form>
            )}

            {requests === null ? (
                <div className="flex justify-center py-10">
                    <Spinner />
                </div>
            ) : requests.length === 0 ? (
                <p className="py-10 text-center text-sm text-muted-foreground">
                    {__('No integrations requested yet. Be the first!')}
                </p>
            ) : (
                <ul className="space-y-2">
                    {requests.map((item) => (
                        <li
                            key={item.id}
                            className="flex items-center justify-between gap-3 rounded-lg border p-3"
                        >
                            <div className="flex min-w-0 items-center gap-2">
                                <a
                                    href={item.url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="truncate font-medium hover:underline"
                                >
                                    {item.name}
                                </a>
                                {item.status === 'pending' && (
                                    <Badge variant="secondary">
                                        {__('Pending review')}
                                    </Badge>
                                )}
                            </div>
                            <Button
                                variant={item.has_voted ? 'default' : 'outline'}
                                size="sm"
                                disabled={
                                    busy || (!item.has_voted && outOfActions)
                                }
                                onClick={() => handleVote(item)}
                                aria-pressed={item.has_voted}
                            >
                                <ChevronUp className="h-4 w-4" />
                                {item.votes_count}
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
