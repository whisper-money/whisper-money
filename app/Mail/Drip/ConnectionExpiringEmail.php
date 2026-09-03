<?php

namespace App\Mail\Drip;

use App\Models\BankingConnection;
use App\Models\User;

class ConnectionExpiringEmail extends DripMail
{
    public function __construct(User $user, public BankingConnection $bankingConnection)
    {
        parent::__construct($user);
    }

    protected function dripSubject(): string
    {
        return __('Your :provider connection expires soon', [
            'provider' => $this->bankingConnection->aspsp_name,
        ]);
    }

    protected function template(): string
    {
        return 'mail.drip.connection-expiring';
    }

    /**
     * @return array<string, mixed>
     */
    protected function contentData(): array
    {
        return [
            'providerName' => $this->bankingConnection->aspsp_name,
            'expiresOn' => $this->bankingConnection->valid_until?->locale(app()->getLocale())->isoFormat('LL'),
            'reconnectUrl' => route('open-banking.reconnect', [
                $this->bankingConnection,
                ...$this->utmParameters(),
            ]),
        ];
    }

    protected function repliesToSender(): bool
    {
        return true;
    }
}
