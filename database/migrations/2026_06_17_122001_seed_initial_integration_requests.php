<?php

use App\Enums\IntegrationRequestStatus;
use App\Models\IntegrationRequest;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * @var list<array{name: string, url: string}>
     */
    private array $integrations = [
        ['name' => 'Bitunix', 'url' => 'https://www.bitunix.com/es-es'],
        ['name' => 'XTB', 'url' => 'https://www.xtb.com/int'],
        ['name' => 'Kraken', 'url' => 'https://www.kraken.com/'],
        ['name' => 'Degiro', 'url' => 'https://www.degiro.es/'],
        ['name' => 'Interactive Brokers', 'url' => 'https://www.interactivebrokers.com/'],
    ];

    public function up(): void
    {
        $user = User::query()->oldest('created_at')->first();

        if ($user === null) {
            return;
        }

        foreach ($this->integrations as $integration) {
            $request = IntegrationRequest::query()->firstOrCreate(
                ['name' => $integration['name'], 'user_id' => $user->id],
                ['url' => $integration['url'], 'status' => IntegrationRequestStatus::Approved],
            );

            $request->votes()->firstOrCreate(['user_id' => $user->id]);
        }
    }

    public function down(): void
    {
        $user = User::query()->oldest('created_at')->first();

        if ($user === null) {
            return;
        }

        IntegrationRequest::query()
            ->where('user_id', $user->id)
            ->whereIn('name', array_column($this->integrations, 'name'))
            ->delete();
    }
};
