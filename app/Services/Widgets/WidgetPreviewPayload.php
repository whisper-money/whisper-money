<?php

namespace App\Services\Widgets;

use App\Models\Account;
use App\Models\User;
use App\Models\WidgetDefinition;
use App\Services\Widgets\Gateway\DashboardVariables;
use Carbon\CarbonImmutable;

/**
 * What the widget preview MCP App (App\Mcp\Resources\WidgetPreview) needs to
 * draw a widget as the dashboard would: its compiled module, its data
 * resolved through the gateway as the user for their current month (each
 * call's body, as the dashboard hands it over), the calls the API refused,
 * and the user's currency, locale and accounts.
 *
 * The widget tools put it in their result's `_meta` under MetaKey
 * (resources/js/widgets/preview/main.tsx reads the same key), so the agent
 * never pays for the module and the data in its context.
 */
class WidgetPreviewPayload
{
    public const string MetaKey = 'whisper-money/widget-preview';

    /**
     * Past this much data the preview says so instead of carrying it: chat
     * clients cap what a tool result may hold.
     */
    private const int MaxDataBytes = 1_000_000;

    public function __construct(private WidgetDefinitionService $widgets) {}

    /**
     * @return array{widget_id: string, name: string, revision: int, js: string, size: array{width: string, height: string}, data: array<string, mixed>|null, data_too_large: bool, failures: list<array{key: string, status: int, message: string}>, currency: string, locale: string, accounts: array<string, array{type: string, bankName: string|null, bankLogo: string|null}>}
     */
    public function for(User $user, WidgetDefinition $widget): array
    {
        $answers = $this->widgets
            ->data($user, [$widget], DashboardVariables::forMonthOf(CarbonImmutable::instance($user->localToday())))[$widget->id];

        $data = array_map(fn (array $answer): mixed => $answer['body'], $answers);
        $tooLarge = strlen((string) json_encode($data)) > self::MaxDataBytes;
        $size = $widget->defaultSize();

        return [
            'widget_id' => $widget->id,
            'name' => $widget->name,
            'revision' => $widget->revision,
            'js' => $widget->compiled_js,
            'size' => ['width' => $size['width']->value, 'height' => $size['height']->value],
            'data' => $tooLarge ? null : $data,
            'data_too_large' => $tooLarge,
            'failures' => $this->failures($answers),
            'currency' => $user->currency_code ?? 'EUR',
            'locale' => $user->formatLocale(),
            'accounts' => $user->accounts()->with('bank:id,name,logo')->get()
                ->mapWithKeys(fn (Account $account): array => [$account->id => [
                    'type' => $account->type->value,
                    'bankName' => $account->bank?->name,
                    'bankLogo' => $account->bank?->logo,
                ]])->all(),
        ];
    }

    /**
     * The calls the API refused, which the dashboard shows in the widget's
     * place instead of drawing it.
     *
     * @param  array<string, array{status: int, body: array<mixed>}>  $answers
     * @return list<array{key: string, status: int, message: string}>
     */
    private function failures(array $answers): array
    {
        $failures = [];

        foreach ($answers as $key => $answer) {
            if ($answer['status'] >= 400) {
                $message = $answer['body']['message'] ?? null;
                $failures[] = [
                    'key' => (string) $key,
                    'status' => $answer['status'],
                    'message' => is_string($message) ? $message : "The call failed with status {$answer['status']}.",
                ];
            }
        }

        return $failures;
    }
}
