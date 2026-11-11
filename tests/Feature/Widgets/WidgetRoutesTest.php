<?php

use App\Enums\ApiCredential;
use App\Enums\SystemWidget;
use App\Features\CustomDashboards;
use App\Models\Account;
use App\Models\ApiRequest;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WidgetDefinition;
use Inertia\Testing\AssertableInertia;
use Laravel\Pennant\Feature;

function widgetOwner(array $attributes = []): User
{
    return User::factory()->onboarded()->create($attributes);
}

/**
 * A widget of $user's that reads their transactions within the dashboard's range.
 */
function transactionsWidget(User $user): WidgetDefinition
{
    return WidgetDefinition::factory()->for($user)->reading([
        'rows' => ['endpoint' => 'GET /transactions', 'params' => ['from' => '{{range.from}}', 'to' => '{{range.to}}'], 'paginate' => 'all'],
    ])->create();
}

/**
 * @return array{0: Transaction, 1: Transaction}
 */
function octoberAndNovemberTransactions(User $user): array
{
    $account = Account::factory()->create(['user_id' => $user->id]);

    return [
        Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'transaction_date' => '2026-10-10']),
        Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'transaction_date' => '2026-11-10']),
    ];
}

function runtimeError(int $revision = 1): array
{
    return ['revision' => $revision, 'phase' => 'render', 'message' => 'x is undefined', 'stack' => 'at Widget', 'componentStack' => null];
}

describe('widget data', function () {
    it('answers each call of the saved manifest for the dashboard range', function () {
        $user = widgetOwner();
        [$october] = octoberAndNovemberTransactions($user);
        $widget = transactionsWidget($user);

        $this->actingAs($user)
            ->getJson(route('widgets.data', ['widget' => $widget, 'range' => ['from' => '2026-10-01', 'to' => '2026-10-31']]))
            ->assertOk()
            ->assertJsonPath('revision', 1)
            ->assertJsonPath('data.rows.status', 200)
            ->assertJsonCount(1, 'data.rows.body.data')
            ->assertJsonPath('data.rows.body.data.0.id', $october->id);
    });

    it('ignores anything the page sends besides the dashboard variables', function () {
        $user = widgetOwner();
        [$october] = octoberAndNovemberTransactions($user);
        $widget = transactionsWidget($user);

        $this->actingAs($user)
            ->getJson(route('widgets.data', [
                'widget' => $widget,
                'range' => ['from' => '2026-10-01', 'to' => '2026-10-31', 'account' => 'x'],
                'from' => '2026-11-01',
                'endpoint' => 'GET /spaces',
                'data' => ['rows' => ['endpoint' => 'GET /spaces']],
                'params' => ['to' => '2026-12-31'],
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonCount(1, 'data.rows.body.data')
            ->assertJsonPath('data.rows.body.data.0.id', $october->id);
    });

    it('reads the user\'s current month when the page sends no range', function () {
        $this->travelTo('2026-11-20 12:00:00');
        $user = widgetOwner();
        [, $november] = octoberAndNovemberTransactions($user);

        $this->actingAs($user)
            ->getJson(route('widgets.data', transactionsWidget($user)))
            ->assertOk()
            ->assertJsonCount(1, 'data.rows.body.data')
            ->assertJsonPath('data.rows.body.data.0.id', $november->id);
    });

    it('refuses a range that ends before it starts', function () {
        $user = widgetOwner();

        $this->actingAs($user)
            ->getJson(route('widgets.data', ['widget' => transactionsWidget($user), 'range' => ['from' => '2026-10-31', 'to' => '2026-10-01']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('range.to');
    });

    it('refuses a range longer than ten years', function () {
        $user = widgetOwner();

        $this->actingAs($user)
            ->getJson(route('widgets.data', ['widget' => transactionsWidget($user), 'range' => ['from' => '2000-01-01', 'to' => '2026-10-01']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('range.to');
    });

    it('does not exist for another user', function () {
        $this->actingAs(widgetOwner())
            ->getJson(route('widgets.data', transactionsWidget(widgetOwner())))
            ->assertNotFound();
    });

    it('records each call in api_requests as a dashboard one', function () {
        $user = widgetOwner();
        $widget = WidgetDefinition::factory()->for($user)->reading([
            'accounts' => ['endpoint' => 'GET /accounts'],
            'again' => ['endpoint' => 'GET /accounts'],
            'categories' => ['endpoint' => 'GET /categories'],
        ])->create();

        $this->actingAs($user)->getJson(route('widgets.data', $widget))->assertOk();

        expect(ApiRequest::query()->orderBy('endpoint')->get(['user_id', 'method', 'endpoint', 'credential', 'status'])->toArray())->toBe([
            ['user_id' => $user->id, 'method' => 'GET', 'endpoint' => 'api.v1.accounts.index', 'credential' => ApiCredential::Dashboard->value, 'status' => 200],
            ['user_id' => $user->id, 'method' => 'GET', 'endpoint' => 'api.v1.categories.index', 'credential' => ApiCredential::Dashboard->value, 'status' => 200],
        ]);
    });

    it('answers the calls past the gateway limit with a 429 of their own', function () {
        config(['widgets.gateway.calls_per_minute' => 1]);
        $user = widgetOwner();
        $widget = WidgetDefinition::factory()->for($user)->reading([
            'accounts' => ['endpoint' => 'GET /accounts'],
            'categories' => ['endpoint' => 'GET /categories'],
        ])->create();

        $this->actingAs($user)
            ->getJson(route('widgets.data', $widget))
            ->assertOk()
            ->assertJsonPath('data.accounts.status', 200)
            ->assertJsonPath('data.categories.status', 429);
    });

    it('answers every user widget of a dashboard in one go, a shared call read once', function () {
        $user = widgetOwner();
        [$october] = octoberAndNovemberTransactions($user);
        $dashboard = Dashboard::factory()->for($user)->create();
        $first = transactionsWidget($user);
        $second = transactionsWidget($user);
        DashboardWidget::factory()->for($dashboard)->system(SystemWidget::Cashflow)->create();
        foreach ([$first, $second] as $widget) {
            DashboardWidget::factory()->for($dashboard)->create(['system_widget' => null, 'widget_definition_id' => $widget->id, 'width' => '1/2', 'height' => 'm']);
        }

        $this->actingAs($user)
            ->getJson(route('dashboards.widgets.data', ['dashboard' => $dashboard, 'range' => ['from' => '2026-10-01', 'to' => '2026-10-31']]))
            ->assertOk()
            ->assertJsonCount(2, 'widgets')
            ->assertJsonPath("widgets.{$first->id}.data.rows.body.data.0.id", $october->id)
            ->assertJsonPath("widgets.{$second->id}.data.rows.body.data.0.id", $october->id);

        expect(ApiRequest::query()->count())->toBe(1);
    });

    it('refuses another user\'s dashboard', function () {
        $this->actingAs(widgetOwner())
            ->getJson(route('dashboards.widgets.data', Dashboard::factory()->create()))
            ->assertForbidden();
    });
});

describe('runtime errors', function () {
    it('keeps the error a widget raised', function () {
        $user = widgetOwner();
        $widget = transactionsWidget($user);

        $this->actingAs($user)->postJson(route('widgets.errors.store', $widget), runtimeError())->assertNoContent();

        expect($widget->fresh()->last_error)->toEqual([
            'revision' => 1, 'phase' => 'render', 'message' => 'x is undefined', 'stack' => 'at Widget', 'component_stack' => null,
        ]);
    });

    it('does not let a user report errors on another user\'s widget', function () {
        $widget = transactionsWidget(widgetOwner());

        $this->actingAs(widgetOwner())->postJson(route('widgets.errors.store', $widget), runtimeError())->assertNotFound();

        expect($widget->fresh()->last_error)->toBeNull();
    });

    it('bounds what it keeps', function () {
        $user = widgetOwner();

        $this->actingAs($user)
            ->postJson(route('widgets.errors.store', transactionsWidget($user)), [...runtimeError(), 'message' => str_repeat('x', 2001), 'phase' => 'boot'])
            ->assertJsonValidationErrors(['message', 'phase']);
    });

    it('is throttled per widget', function () {
        $user = widgetOwner();
        $widget = transactionsWidget($user);

        foreach (range(1, 30) as $attempt) {
            $this->actingAs($user)->postJson(route('widgets.errors.store', $widget), runtimeError())->assertNoContent();
        }

        $this->actingAs($user)->postJson(route('widgets.errors.store', $widget), runtimeError())->assertTooManyRequests();
        $this->actingAs($user)->postJson(route('widgets.errors.store', transactionsWidget($user)), runtimeError())->assertNoContent();
    });
});

describe('placing and reverting from the web', function () {
    it('places a user widget on a dashboard at a size its manifest admits', function () {
        $user = widgetOwner();
        $widget = transactionsWidget($user);
        $dashboard = Dashboard::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('dashboards.widgets.store', $dashboard), ['widget_definition_id' => $widget->id, 'width' => '1/1', 'height' => 'm'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect($dashboard->widgets()->sole())
            ->widget_definition_id->toBe($widget->id)
            ->width->value->toBe('1/1');
    });

    it('refuses a size the manifest does not admit, when placing and when resizing', function () {
        $user = widgetOwner();
        $widget = transactionsWidget($user);
        $dashboard = Dashboard::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('dashboards.widgets.store', $dashboard), ['widget_definition_id' => $widget->id, 'width' => '1/3', 'height' => 'm'])
            ->assertSessionHasErrors('width');

        $this->actingAs($user)->post(route('dashboards.widgets.store', $dashboard), ['widget_definition_id' => $widget->id]);
        $placement = $dashboard->widgets()->sole();

        $this->actingAs($user)
            ->patch(route('dashboards.widgets.update', [$dashboard, $placement]), ['width' => '1/2', 'height' => 'l'])
            ->assertSessionHasErrors('width');
    });

    it('caps a dashboard at its widget limit, system widgets included', function () {
        $user = widgetOwner();
        $dashboard = Dashboard::factory()->for($user)->create();
        DashboardWidget::factory()->for($dashboard)->count(Dashboard::MAX_WIDGETS)->create(['system_widget' => null, 'widget_definition_id' => transactionsWidget($user)->id, 'width' => '1/2', 'height' => 'm']);

        $this->actingAs($user)
            ->post(route('dashboards.widgets.store', $dashboard), ['system_widget' => 'cashflow'])
            ->assertSessionHasErrors('dashboard');

        expect($dashboard->widgets()->count())->toBe(Dashboard::MAX_WIDGETS);
    });

    it('does not place another user\'s widget', function () {
        $user = widgetOwner();
        $dashboard = Dashboard::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('dashboards.widgets.store', $dashboard), ['widget_definition_id' => transactionsWidget(widgetOwner())->id])
            ->assertSessionHasErrors('widget_definition_id');

        expect($dashboard->widgets()->count())->toBe(0);
    });

    it('goes back to an earlier revision', function () {
        $user = widgetOwner();
        $widget = WidgetDefinition::factory()->for($user)->create();

        $this->actingAs($user)->post(route('widgets.revert', $widget), ['revision' => 1])->assertRedirect()->assertSessionHasNoErrors();

        expect($widget->fresh()->revision)->toBe(2)
            ->and($widget->revisions()->where('number', 2)->sole()->restored_from)->toBe(1);
    });

    it('answers a missing revision as a form error', function () {
        $user = widgetOwner();

        $this->actingAs($user)
            ->post(route('widgets.revert', transactionsWidget($user)), ['revision' => 4])
            ->assertSessionHasErrors('revision');
    });

    it('does not let a user revert another user\'s widget', function () {
        $this->actingAs(widgetOwner())
            ->post(route('widgets.revert', transactionsWidget(widgetOwner())), ['revision' => 1])
            ->assertNotFound();
    });
});

describe('paid plan and shared accounts', function () {
    beforeEach(function () {
        config(['subscriptions.enabled' => true, 'app.demo.email' => 'demo@example.test']);
    });

    it('keeps a free-plan user who gets past the paywall from changing widgets', function () {
        $user = widgetOwner(['paywall_seen_at' => now()]);
        $widget = transactionsWidget($user);

        $this->actingAs($user)->post(route('widgets.revert', $widget), ['revision' => 1])->assertForbidden();

        expect($widget->fresh()->revision)->toBe(1);
    });

    it('keeps the shared demo account from writing', function () {
        $user = User::factory()->onboarded()->subscribed()->create(['email' => 'demo@example.test']);
        $widget = transactionsWidget($user);

        $this->actingAs($user)->post(route('widgets.errors.store', $widget), runtimeError())->assertSessionHasErrors('demo');
        $this->actingAs($user)->post(route('widgets.revert', $widget), ['revision' => 1])->assertSessionHasErrors('demo');

        expect($widget->fresh())->revision->toBe(1)->last_error->toBeNull();
    });
});

describe('dashboard page', function () {
    it('defers the user widgets of the dashboard on screen with what the runtime needs', function () {
        $user = widgetOwner();
        $widget = transactionsWidget($user);
        transactionsWidget($user);
        $dashboard = Dashboard::factory()->for($user)->create();
        DashboardWidget::factory()->for($dashboard)->create(['system_widget' => null, 'widget_definition_id' => $widget->id, 'width' => '1/2', 'height' => 'm']);
        $widget->forceFill(['last_error' => ['revision' => 1, 'phase' => 'render', 'message' => 'boom', 'stack' => null, 'component_stack' => null]])->save();

        $this->actingAs($user)
            ->get(route('dashboard', ['dashboard' => $dashboard->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->missing('userWidgets')
                ->loadDeferredProps('dashboard', fn (AssertableInertia $reload) => $reload
                    ->has('userWidgets', 1)
                    ->where("userWidgets.{$widget->id}.js", $widget->compiled_js)
                    ->where("userWidgets.{$widget->id}.revision", 1)
                    ->where("userWidgets.{$widget->id}.manifest.size.width", '1/2')
                    ->where("userWidgets.{$widget->id}.last_error.message", 'boom')
                    ->where("userWidgets.{$widget->id}.revisions.0.number", 1)
                ));
    });

    it('defers every widget of the user, named and sized, for edit mode to place', function () {
        $user = widgetOwner();
        Feature::for($user)->activate(CustomDashboards::class);
        $groceries = WidgetDefinition::factory()->for($user)->create(['name' => 'Groceries']);
        $budget = WidgetDefinition::factory()->for($user)->create(['name' => 'Budget']);
        WidgetDefinition::factory()->create(['name' => 'Someone else']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->missing('widgetDefinitions')
                ->loadDeferredProps('dashboard', fn (AssertableInertia $reload) => $reload
                    ->has('widgetDefinitions', 2)
                    ->where('widgetDefinitions.0', [
                        'id' => $budget->id,
                        'name' => 'Budget',
                        'size' => ['width' => '1/2', 'height' => 'm', 'allowed_widths' => ['1/2', '1/1']],
                    ])
                    ->where('widgetDefinitions.1.id', $groceries->id)
                ));
    });

    it('lists no widget to place with the flag off', function () {
        $user = widgetOwner();
        WidgetDefinition::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->loadDeferredProps('dashboard', fn (AssertableInertia $reload) => $reload
                    ->where('widgetDefinitions', [])
                ));
    });
});
