import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { BankingConnection } from '@/types/banking';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { useState } from 'react';

interface UpdateCredentialsDialogProps {
    connection: BankingConnection;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

export function UpdateCredentialsDialog({
    connection,
    open,
    onOpenChange,
}: UpdateCredentialsDialogProps) {
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [apiToken, setApiToken] = useState('');
    const [apiKey, setApiKey] = useState('');
    const [apiSecret, setApiSecret] = useState('');
    const [coinbaseKeyName, setCoinbaseKeyName] = useState('');
    const [coinbasePrivateKey, setCoinbasePrivateKey] = useState('');
    const [error, setError] = useState<string | null>(null);

    const isIndexaCapital = connection.provider === 'indexacapital';
    const isBinance = connection.provider === 'binance';
    const isBitpanda = connection.provider === 'bitpanda';
    const isCoinbase = connection.provider === 'coinbase';

    const isValid = isIndexaCapital
        ? apiToken.length > 0
        : isBinance
          ? apiKey.length > 0 && apiSecret.length > 0
          : isBitpanda
            ? apiKey.length > 0
            : isCoinbase
              ? coinbaseKeyName.length > 0 && coinbasePrivateKey.length > 0
              : false;

    function handleSubmit() {
        setIsSubmitting(true);
        setError(null);

        const data = isIndexaCapital
            ? { api_token: apiToken }
            : isBinance
              ? { api_key: apiKey, api_secret: apiSecret }
              : isCoinbase
                ? {
                      api_key_name: coinbaseKeyName,
                      private_key: coinbasePrivateKey,
                  }
                : { api_key: apiKey };

        router.patch(
            `/settings/connections/${connection.id}/credentials`,
            data,
            {
                onSuccess: () => {
                    onOpenChange(false);
                    resetState();
                },
                onError: (errors) => {
                    setError(
                        errors.credentials ??
                            errors.api_token ??
                            errors.api_key ??
                            errors.api_secret ??
                            errors.api_key_name ??
                            errors.private_key ??
                            __(
                                'Failed to update credentials. Please try again.',
                            ),
                    );
                },
                onFinish: () => {
                    setIsSubmitting(false);
                },
            },
        );
    }

    function resetState() {
        setApiToken('');
        setApiKey('');
        setApiSecret('');
        setCoinbaseKeyName('');
        setCoinbasePrivateKey('');
        setError(null);
    }

    function handleOpenChange(value: boolean) {
        if (!value) {
            resetState();
        }
        onOpenChange(value);
    }

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent className="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle>{__('Update Credentials')}</DialogTitle>
                    <DialogDescription>
                        {__('Enter your new API credentials for :provider.', {
                            provider: connection.aspsp_name,
                        })}
                    </DialogDescription>
                </DialogHeader>

                {error && <p className="text-sm text-destructive">{error}</p>}

                <div className="space-y-4">
                    {isIndexaCapital && (
                        <div className="space-y-2">
                            <Label htmlFor="update-api-token">
                                {__('API Token')}
                            </Label>
                            <Input
                                id="update-api-token"
                                type="password"
                                value={apiToken}
                                onChange={(e) => setApiToken(e.target.value)}
                                placeholder={__(
                                    'Paste your Indexa Capital API token',
                                )}
                            />
                            <p className="text-xs text-muted-foreground">
                                {__(
                                    'You can generate your API token from your Indexa Capital dashboard under',
                                )}{' '}
                                <a
                                    href="https://indexacapital.com/es/u/user#settings-apps"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="underline"
                                >
                                    {__('Settings > Applications')}
                                </a>
                                .
                            </p>
                        </div>
                    )}

                    {isBinance && (
                        <>
                            <div className="space-y-2">
                                <Label htmlFor="update-api-key">
                                    {__('API Key')}
                                </Label>
                                <Input
                                    id="update-api-key"
                                    type="password"
                                    value={apiKey}
                                    onChange={(e) => setApiKey(e.target.value)}
                                    placeholder={__(
                                        'Paste your Binance API Key',
                                    )}
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="update-api-secret">
                                    {__('API Secret')}
                                </Label>
                                <Input
                                    id="update-api-secret"
                                    type="password"
                                    value={apiSecret}
                                    onChange={(e) =>
                                        setApiSecret(e.target.value)
                                    }
                                    placeholder={__(
                                        'Paste your Binance API Secret',
                                    )}
                                />
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {__(
                                    'You can create API keys from your Binance account under',
                                )}{' '}
                                <a
                                    href="https://www.binance.com/es/my/settings/api-management"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="underline"
                                >
                                    {__('API Management')}
                                </a>
                                .
                            </p>
                        </>
                    )}

                    {isBitpanda && (
                        <div className="space-y-2">
                            <Label htmlFor="update-bitpanda-api-key">
                                {__('API Key')}
                            </Label>
                            <Input
                                id="update-bitpanda-api-key"
                                type="password"
                                value={apiKey}
                                onChange={(e) => setApiKey(e.target.value)}
                                placeholder={__('Paste your Bitpanda API Key')}
                            />
                            <p className="text-xs text-muted-foreground">
                                {__(
                                    'You can create API keys from your Bitpanda account under',
                                )}{' '}
                                <a
                                    href="https://web.bitpanda.com/apikey"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="underline"
                                >
                                    {__('API Key Management')}
                                </a>
                                .
                            </p>
                        </div>
                    )}

                    {isCoinbase && (
                        <div className="space-y-4">
                            <div className="space-y-2">
                                <Label htmlFor="update-coinbase-key-name">
                                    {__('App Key ID')}
                                </Label>
                                <Input
                                    id="update-coinbase-key-name"
                                    type="text"
                                    value={coinbaseKeyName}
                                    onChange={(e) =>
                                        setCoinbaseKeyName(e.target.value)
                                    }
                                    className="font-mono text-xs"
                                    placeholder="00000000-0000-0000-0000-000000000000"
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="update-coinbase-private-key">
                                    {__('Secret')}
                                </Label>
                                <Textarea
                                    id="update-coinbase-private-key"
                                    value={coinbasePrivateKey}
                                    onChange={(e) =>
                                        setCoinbasePrivateKey(e.target.value)
                                    }
                                    rows={6}
                                    className="font-mono text-xs"
                                    placeholder={
                                        'Paste your CDP API key secret'
                                    }
                                />
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {__(
                                    'Create a CDP API key (Ed25519 recommended) in the Coinbase Developer Platform under',
                                )}{' '}
                                <a
                                    href="https://portal.cdp.coinbase.com/access/api"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="underline"
                                >
                                    {__('API Keys')}
                                </a>
                                .
                            </p>
                        </div>
                    )}
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => handleOpenChange(false)}
                        disabled={isSubmitting}
                    >
                        {__('Cancel')}
                    </Button>
                    <Button
                        onClick={handleSubmit}
                        disabled={isSubmitting || !isValid}
                    >
                        {isSubmitting
                            ? __('Updating...')
                            : __('Update Credentials')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
