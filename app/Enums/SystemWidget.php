<?php

namespace App\Enums;

use App\Contracts\SizedWidget;

/**
 * The widgets the app ships with, mirroring `SYSTEM_WIDGETS` in
 * `resources/js/components/dashboard/widgets/registry.ts`. The values are the
 * registry's stable ids, stored on `dashboard_widgets.system_widget`: renaming
 * one orphans it on every saved dashboard. `SystemWidgetRegistryParityTest`
 * fails when this list and the TypeScript registry drift apart.
 */
enum SystemWidget: string implements SizedWidget
{
    case NetWorth = 'net-worth';
    case AccountBalances = 'account-balances';
    case MonthlySavings = 'monthly-savings';
    case TopCategories = 'top-categories';
    case TopLabels = 'top-labels';
    case Cashflow = 'cashflow';

    /** @return array{width: WidgetWidth, height: WidgetHeight} */
    public function defaultSize(): array
    {
        return match ($this) {
            self::NetWorth, self::AccountBalances, self::TopCategories => ['width' => WidgetWidth::Full, 'height' => WidgetHeight::Large],
            self::MonthlySavings, self::TopLabels, self::Cashflow => ['width' => WidgetWidth::Full, 'height' => WidgetHeight::Medium],
        };
    }

    /** @return list<WidgetWidth> */
    public function allowedWidths(): array
    {
        return match ($this) {
            self::MonthlySavings => WidgetWidth::cases(),
            default => [WidgetWidth::Half, WidgetWidth::Full],
        };
    }

    /** @return list<WidgetHeight> */
    public function allowedHeights(): array
    {
        return match ($this) {
            self::Cashflow => [WidgetHeight::Medium],
            default => WidgetHeight::cases(),
        };
    }

    public function allowsSize(WidgetWidth $width, WidgetHeight $height): bool
    {
        return in_array($width, $this->allowedWidths(), true)
            && in_array($height, $this->allowedHeights(), true);
    }
}
