<?php

namespace App\Services\Widgets;

use App\Enums\WidgetHeight;
use App\Enums\WidgetOrigin;
use App\Enums\WidgetRecheck;
use App\Enums\WidgetWidth;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\User;
use App\Models\WidgetDefinition;
use App\Models\WidgetRevision;
use App\Policies\WidgetDefinitionPolicy;
use App\Services\Widgets\Gateway\DashboardVariables;
use App\Services\Widgets\Gateway\ManifestValidator;
use App\Services\Widgets\Gateway\WidgetDataGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Saves the widgets a user's agent writes, from the web or through MCP, and
 * places them on dashboards. Saving checks the manifest against the gateway's
 * registry, validates and compiles the TSX, and keeps every version as a
 * revision; nothing invalid is ever stored, and what is wrong comes back in
 * one shape ({@see InvalidWidget}) the agent can act on.
 *
 * Who may do what is the callers' job ({@see WidgetDefinitionPolicy}).
 */
class WidgetDefinitionService
{
    /**
     * The largest TSX a widget may have, in bytes. The compiler refuses
     * anything over 60,000 characters too; this stops it before Bun starts.
     */
    public const int MaxSourceBytes = 60_000;

    public function __construct(
        private ManifestValidator $manifests,
        private WidgetCompiler $compiler,
        private WidgetDataGateway $gateway,
    ) {}

    /**
     * @param  array<mixed>  $manifest
     *
     * @throws InvalidWidget
     */
    public function create(User $user, array $manifest, string $source, WidgetOrigin $origin): WidgetDefinition
    {
        if ($user->widgetDefinitions()->count() >= WidgetDefinition::MAX_PER_USER) {
            throw InvalidWidget::at('widget', 'You already have '.WidgetDefinition::MAX_PER_USER.' widgets, the most you can keep.', 'Update one of them instead of creating another.');
        }

        $content = $this->check($manifest, $source);

        return DB::transaction(function () use ($user, $content, $origin): WidgetDefinition {
            $widget = $user->widgetDefinitions()->make(['created_via' => $origin, 'revision' => 0]);

            return $this->saveRevision($widget, $content, $origin);
        });
    }

    /**
     * Save a new revision of $widget.
     *
     * @param  array<mixed>  $manifest
     *
     * @throws InvalidWidget
     */
    public function update(WidgetDefinition $widget, array $manifest, string $source, WidgetOrigin $origin): WidgetDefinition
    {
        $content = $this->check($manifest, $source);

        DB::transaction(fn (): WidgetDefinition => $this->saveRevision($this->locked($widget), $content, $origin));

        return $widget->refresh();
    }

    /**
     * Go back to revision $number, by saving its content as a new revision.
     * It is validated and compiled again: a rule added since it was saved
     * applies to it too.
     *
     * @throws InvalidWidget
     */
    public function revert(WidgetDefinition $widget, int $number, WidgetOrigin $origin): WidgetDefinition
    {
        $revision = $widget->revisions()->where('number', $number)->first()
            ?? throw InvalidWidget::at('revision', "The widget has no revision {$number}. Its revisions kept are: ".$widget->revisions()->orderBy('number')->pluck('number')->implode(', ').'.');

        $content = $this->check($revision->manifest, $revision->source);

        DB::transaction(fn (): WidgetDefinition => $this->saveRevision($this->locked($widget), $content, $origin, restoredFrom: $number));

        return $widget->refresh();
    }

    /**
     * Place $widget on $dashboard, at $size or its manifest's default size.
     *
     * @param  array{width: WidgetWidth, height: WidgetHeight}|null  $size
     *
     * @throws InvalidWidget
     */
    public function place(Dashboard $dashboard, WidgetDefinition $widget, ?array $size = null): DashboardWidget
    {
        if ($dashboard->user_id !== $widget->user_id) {
            throw InvalidWidget::at('widget', 'The widget does not exist.');
        }

        $size ??= $widget->defaultSize();

        if (! $widget->allowsSize($size['width'], $size['height'])) {
            throw InvalidWidget::at('size', "The widget does not admit the size {$size['width']->value} × {$size['height']->value}.", 'Use one of the sizes its manifest lists in size.allowed_widths and size.allowed_heights.');
        }

        if ($dashboard->widgets()->count() >= Dashboard::MAX_WIDGETS) {
            throw InvalidWidget::at('dashboard', 'The dashboard already holds '.Dashboard::MAX_WIDGETS.' widgets, the most it can.', 'Remove one first, or place it on another dashboard.');
        }

        return $dashboard->widgets()->create([
            'widget_definition_id' => $widget->id,
            ...$size,
            'position' => (int) $dashboard->widgets()->max('position') + 1,
        ]);
    }

    /**
     * Keep the runtime error the dashboard caught in $widget, unless it came
     * from a revision that is no longer the current one: a tab still open on
     * old code must not overwrite what the new code did.
     *
     * @param  array{phase: string, message: string, stack: string|null, component_stack: string|null}  $error
     */
    public function recordError(WidgetDefinition $widget, int $revision, array $error): bool
    {
        return WidgetDefinition::query()
            ->whereKey($widget->id)
            ->where('revision', $revision)
            ->update([
                'last_error' => json_encode(['revision' => $revision, ...$error], JSON_THROW_ON_ERROR),
                'last_error_at' => now(),
            ]) === 1;
    }

    /**
     * Check $widget again under the current rules (WidgetRules::VERSION)
     * before its module is served, unless it was already, or $force says to
     * anyway. Its stored TSX is validated and compiled again: when it passes,
     * the new module is stored; when it does not, its module is replaced by
     * an inert one and the reasons are kept as its last error (phase
     * `rules`), for the agent to fix with update_widget. Neither is a new
     * revision, nor an edit the dashboard shows: `updated_at` is left alone.
     * When the compiler cannot judge it, $widget is only served inert this
     * time, and checked again on its next load.
     */
    public function recheck(WidgetDefinition $widget, bool $force = false): WidgetRecheck
    {
        if (! $force && $widget->rules_version >= WidgetRules::VERSION) {
            return WidgetRecheck::Current;
        }

        $compilation = $this->compiler->compile($widget->source);

        if ($compilation->ok && $compilation->js !== null) {
            $wasBlocked = ($widget->last_error['phase'] ?? null) === WidgetRules::ErrorPhase;

            $this->storeRecheck($widget, [
                'compiled_js' => $compilation->js,
                'rules_version' => WidgetRules::VERSION,
                ...($wasBlocked ? ['last_error' => null, 'last_error_at' => null] : []),
            ]);

            return WidgetRecheck::Recompiled;
        }

        if ($this->compilerFailed($compilation)) {
            // Fail closed for this request only: nothing is stored.
            $widget->forceFill([
                'compiled_js' => WidgetRules::InertModule,
                'last_error' => $this->rulesError($widget, 'This widget could not be checked against the current widget rules, so it is not drawn. It is checked again the next time it loads.', []),
            ]);

            return WidgetRecheck::Unavailable;
        }

        $this->storeRecheck($widget, [
            'compiled_js' => WidgetRules::InertModule,
            'rules_version' => WidgetRules::VERSION,
            'last_error' => $this->rulesError(
                $widget,
                'This widget no longer passes the widget rules, which changed since it was saved, so it is not drawn. Fix every error listed and save it again with update_widget.',
                array_map(fn (array $error): array => ['path' => "source:{$error['line']}:{$error['column']}", 'message' => $error['message']], $compilation->errors),
            ),
            'last_error_at' => now(),
        ]);

        return WidgetRecheck::Neutralised;
    }

    /**
     * The compiler itself broke or timed out: it said nothing about the widget.
     */
    private function compilerFailed(WidgetCompilation $compilation): bool
    {
        return collect($compilation->errors)->contains(fn (array $error): bool => in_array($error['code'], ['compiler', 'timeout'], true));
    }

    /**
     * @param  list<array{path: string, message: string}>  $errors
     * @return array{revision: int, phase: string, message: string, stack: null, component_stack: null, errors: list<array{path: string, message: string}>}
     */
    private function rulesError(WidgetDefinition $widget, string $message, array $errors): array
    {
        return [
            'revision' => $widget->revision,
            'phase' => WidgetRules::ErrorPhase,
            'message' => $message,
            'stack' => null,
            'component_stack' => null,
            'errors' => $errors,
        ];
    }

    /**
     * Store what a recheck found, only if $widget is still at the revision
     * that was checked (a save meanwhile has already stored a newer module,
     * checked under the current rules), and without touching `updated_at`.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function storeRecheck(WidgetDefinition $widget, array $attributes): void
    {
        $columns = array_map(fn (mixed $value): mixed => is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value, $attributes);

        $stored = WidgetDefinition::query()
            ->whereKey($widget->id)
            ->where('revision', $widget->revision)
            ->toBase()
            ->update($columns) === 1;

        if ($stored) {
            $widget->forceFill($attributes)->syncOriginal();
        } else {
            $widget->refresh();
        }
    }

    /**
     * The data each of $widgets reads, resolved as $user through the gateway,
     * by widget id. All their calls go in one resolution, so two widgets that
     * read the same thing cost one call. $origin says who asked: the
     * dashboard, or the agent's preview.
     *
     * @param  iterable<WidgetDefinition>  $widgets
     * @return array<string, array<string, array{status: int, body: array<mixed>}>>
     */
    public function data(User $user, iterable $widgets, DashboardVariables $variables, WidgetOrigin $origin): array
    {
        $calls = [];
        $result = [];

        foreach ($widgets as $widget) {
            $result[$widget->id] = [];

            foreach ($widget->manifest['data'] as $key => $call) {
                $calls["{$widget->id}.{$key}"] = $call;
            }
        }

        $answers = $this->gateway->resolve($user, $calls, $variables);

        foreach ($answers as $qualifiedKey => $answer) {
            [$id, $key] = explode('.', (string) $qualifiedKey, 2);
            $result[$id][$key] = $answer;
        }

        $this->logGatewayUsage($origin, count($result), $answers);

        return $result;
    }

    /**
     * A count for the feature's metrics: how many calls a read made and how
     * many the API refused, the row cap cut short, or the gateway's limiter
     * turned away. Never what they asked for or answered.
     *
     * @param  array<string, array{status: int, body: array<mixed>}>  $answers
     */
    private function logGatewayUsage(WidgetOrigin $origin, int $widgets, array $answers): void
    {
        $answers = collect($answers);

        Log::info('User widget data read', [
            'source' => $origin->value,
            'widgets' => $widgets,
            'calls' => $answers->count(),
            'failed_calls' => $answers->where('status', '>=', 400)->count(),
            'truncated_calls' => $answers->filter(fn (array $answer): bool => $answer['status'] < 400 && ($answer['body']['truncated'] ?? false) === true)->count(),
            'rate_limited_calls' => $answers->where('status', 429)->count(),
        ]);
    }

    /**
     * The manifest once valid and the compiled module, or every reason why not.
     *
     * @param  array<mixed>  $manifest
     *
     * @throws InvalidWidget
     */
    private function check(array $manifest, string $source): WidgetContent
    {
        if (strlen($source) > self::MaxSourceBytes) {
            throw InvalidWidget::at(
                'source',
                'The widget source is '.number_format(strlen($source)).' bytes; the most a widget may have is '.number_format(self::MaxSourceBytes).'.',
                'Leave out what the widget does not draw, or split it into two widgets.',
            );
        }

        $errors = [];

        try {
            $manifest = $this->manifests->validate($manifest);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $errors[] = ['path' => "manifest.{$field}", 'message' => $message];
                }
            }
        }

        $compilation = $this->compiler->compile($source);

        foreach ($compilation->errors as $error) {
            $errors[] = ['path' => "source:{$error['line']}:{$error['column']}", 'message' => $error['message']];
        }

        if ($errors !== [] || $compilation->js === null) {
            throw new InvalidWidget($errors ?: [['path' => 'source', 'message' => 'The widget could not be compiled.']]);
        }

        return new WidgetContent($manifest, $source, $compilation->js);
    }

    /**
     * $widget locked for the rest of the transaction, so two saves at once
     * cannot both take the same revision number.
     */
    private function locked(WidgetDefinition $widget): WidgetDefinition
    {
        return WidgetDefinition::query()->lockForUpdate()->findOrFail($widget->id);
    }

    /**
     * Make $content the widget's next revision. Runs inside a transaction.
     */
    private function saveRevision(WidgetDefinition $widget, WidgetContent $content, WidgetOrigin $origin, ?int $restoredFrom = null): WidgetDefinition
    {
        $number = $widget->revision + 1;

        $widget->fill([
            'name' => $content->manifest['name'],
            ...$content->attributes(),
            'revision' => $number,
            'rules_version' => WidgetRules::VERSION,
            // The error came from the code this revision replaces.
            'last_error' => null,
            'last_error_at' => null,
        ])->save();

        $widget->revisions()->create([
            'number' => $number,
            ...$content->attributes(),
            'created_via' => $origin,
            'restored_from' => $restoredFrom,
        ]);

        $this->pruneRevisions($widget);
        $this->refitPlacements($widget);

        $this->logSaved($origin, $number, $restoredFrom);

        return $widget;
    }

    /**
     * A count for the feature's metrics, once the revision is in: who wrote
     * it and how, never its name or its code.
     */
    private function logSaved(WidgetOrigin $origin, int $number, ?int $restoredFrom): void
    {
        $action = match (true) {
            $restoredFrom !== null => 'reverted',
            $number === 1 => 'created',
            default => 'updated',
        };

        DB::afterCommit(fn () => Log::info('User widget saved', [
            'source' => $origin->value,
            'action' => $action,
            'revision' => $number,
        ]));
    }

    /**
     * Keep the newest WidgetRevision::MAX_KEPT revisions: an agent fixing a
     * widget in a loop saves one per try.
     */
    private function pruneRevisions(WidgetDefinition $widget): void
    {
        $widget->revisions()->where('number', '<=', $widget->revision - WidgetRevision::MAX_KEPT)->delete();
    }

    /**
     * Put back at its default size every placement whose size the new
     * manifest no longer admits.
     */
    private function refitPlacements(WidgetDefinition $widget): void
    {
        $widget->placements()->get()
            ->reject(fn (DashboardWidget $placement): bool => $widget->allowsSize($placement->width, $placement->height))
            ->each(fn (DashboardWidget $placement): bool => $placement->update($widget->defaultSize()));
    }
}
