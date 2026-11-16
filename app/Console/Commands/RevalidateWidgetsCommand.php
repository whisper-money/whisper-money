<?php

namespace App\Console\Commands;

use App\Enums\WidgetRecheck;
use App\Models\WidgetDefinition;
use App\Services\Widgets\WidgetDefinitionService;
use App\Services\Widgets\WidgetRules;
use Illuminate\Console\Command;

/**
 * Checks the stored user widgets again under the current widget rules, as a
 * deploy that changed them should: each one still passing is compiled again,
 * each one that does not is neutralised (served as an inert module, the
 * reasons kept as its last error). The dashboard and the MCP tools do the
 * same for a widget they serve, so a row this misses is never served as it
 * was; running it at deploy time spares the first page load the compile.
 *
 * It fails only when the compiler itself is unavailable: it stops there, and
 * the widgets it could not judge stay as they were, to be checked again on
 * their next load.
 */
class RevalidateWidgetsCommand extends Command
{
    protected $signature = 'widgets:revalidate {--all : Check every widget, not only those saved under older widget rules}';

    protected $description = 'Check stored user widgets again under the current widget rules, recompiling or neutralising them';

    public function handle(WidgetDefinitionService $widgets): int
    {
        $force = (bool) $this->option('all');
        $counts = array_fill_keys(array_column(WidgetRecheck::cases(), 'value'), 0);

        WidgetDefinition::query()
            ->when(! $force, fn ($query) => $query->where('rules_version', '<', WidgetRules::VERSION))
            ->lazyById(50)
            ->each(function (WidgetDefinition $widget) use ($widgets, $force, &$counts): bool {
                $outcome = $widgets->recheck($widget, $force);
                $counts[$outcome->value]++;

                // Without a compiler the rest would only wait for it in turn.
                return $outcome !== WidgetRecheck::Unavailable;
            });

        $checked = $counts[WidgetRecheck::Recompiled->value] + $counts[WidgetRecheck::Neutralised->value];

        $this->info(sprintf(
            'Widget rules v%d: %d checked, %d recompiled, %d neutralised, %d not checked (compiler unavailable).',
            WidgetRules::VERSION,
            $checked,
            $counts[WidgetRecheck::Recompiled->value],
            $counts[WidgetRecheck::Neutralised->value],
            $counts[WidgetRecheck::Unavailable->value],
        ));

        return $counts[WidgetRecheck::Unavailable->value] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
