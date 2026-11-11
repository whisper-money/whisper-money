<?php

use App\Enums\SystemWidget;
use App\Enums\WidgetHeight;
use App\Enums\WidgetWidth;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

function dashboardOwner(): User
{
    return User::factory()->onboarded()->create();
}

describe('dashboards', function () {
    it('creates a dashboard at the end of the list and opens it', function () {
        $user = dashboardOwner();
        Dashboard::factory()->for($user)->create(['position' => 3]);

        $response = $this->actingAs($user)->post(route('dashboards.store'), ['name' => 'Spending']);

        $dashboard = $user->dashboards()->where('name', 'Spending')->sole();
        $response->assertRedirect(route('dashboard', ['dashboard' => $dashboard->id]));
        expect($dashboard->position)->toBe(4);
    });

    it('caps how many dashboards a user keeps', function () {
        $user = dashboardOwner();
        Dashboard::factory()->for($user)->count(Dashboard::MAX_PER_USER)->create();

        $this->actingAs($user)
            ->post(route('dashboards.store'), ['name' => 'One too many'])
            ->assertSessionHasErrors('name');

        expect($user->dashboards()->count())->toBe(Dashboard::MAX_PER_USER);
    });

    it('renames a dashboard', function () {
        $dashboard = Dashboard::factory()->for(dashboardOwner())->create();

        $this->actingAs($dashboard->user)
            ->patch(route('dashboards.update', $dashboard), ['name' => 'Renamed'])
            ->assertRedirect();

        expect($dashboard->fresh()->name)->toBe('Renamed');
    });

    it('reorders the user\'s dashboards', function () {
        $user = dashboardOwner();
        [$first, $second] = Dashboard::factory()->for($user)->count(2)->sequence(['position' => 0], ['position' => 1])->create();

        $this->actingAs($user)
            ->patch(route('dashboards.reorder'), ['ids' => [$second->id, $first->id]])
            ->assertRedirect();

        expect($second->fresh()->position)->toBe(0)
            ->and($first->fresh()->position)->toBe(1);
    });

    it('deletes a dashboard with its widgets', function () {
        $dashboard = Dashboard::factory()->for(dashboardOwner())->create();
        $widget = DashboardWidget::factory()->for($dashboard)->create();

        $this->actingAs($dashboard->user)
            ->delete(route('dashboards.destroy', $dashboard))
            ->assertRedirect(route('dashboard'));

        expect(Dashboard::find($dashboard->id))->toBeNull()
            ->and(DashboardWidget::find($widget->id))->toBeNull();
    });
});

describe('default dashboard', function () {
    it('picks one of the user\'s dashboards and goes back to the system one', function () {
        $dashboard = Dashboard::factory()->for(dashboardOwner())->create();
        $user = $dashboard->user;

        $this->actingAs($user)->put(route('dashboards.default.update'), ['dashboard_id' => $dashboard->id])->assertRedirect();
        expect($user->fresh()->default_dashboard_id)->toBe($dashboard->id);

        $this->actingAs($user)->put(route('dashboards.default.update'), ['dashboard_id' => null])->assertRedirect();
        expect($user->fresh()->default_dashboard_id)->toBeNull();
    });

    it('goes back to the system dashboard when the default one is deleted', function () {
        $dashboard = Dashboard::factory()->for(dashboardOwner())->create();
        $user = $dashboard->user;
        $user->forceFill(['default_dashboard_id' => $dashboard->id])->save();

        $this->actingAs($user)->delete(route('dashboards.destroy', $dashboard));

        expect($user->fresh()->default_dashboard_id)->toBeNull();
    });

    it('refuses another user\'s dashboard as default', function () {
        $user = dashboardOwner();
        $foreign = Dashboard::factory()->create();

        $this->actingAs($user)
            ->put(route('dashboards.default.update'), ['dashboard_id' => $foreign->id])
            ->assertSessionHasErrors('dashboard_id');

        expect($user->fresh()->default_dashboard_id)->toBeNull();
    });
});

describe('widgets', function () {
    it('places a system widget at its default size at the end of the dashboard', function () {
        $dashboard = Dashboard::factory()->for(dashboardOwner())->create();
        DashboardWidget::factory()->for($dashboard)->system(SystemWidget::Cashflow)->create(['position' => 2]);

        $this->actingAs($dashboard->user)
            ->post(route('dashboards.widgets.store', $dashboard), ['system_widget' => 'net-worth'])
            ->assertRedirect();

        $widget = $dashboard->widgets()->where('system_widget', 'net-worth')->sole();
        expect($widget->width)->toBe(WidgetWidth::Full)
            ->and($widget->height)->toBe(WidgetHeight::Large)
            ->and($widget->position)->toBe(3)
            ->and($widget->widget_definition_id)->toBeNull();
    });

    it('places a system widget at a size it admits', function () {
        $dashboard = Dashboard::factory()->for(dashboardOwner())->create();

        $this->actingAs($dashboard->user)
            ->post(route('dashboards.widgets.store', $dashboard), ['system_widget' => 'monthly-savings', 'width' => '1/3', 'height' => 'm'])
            ->assertRedirect();

        expect($dashboard->widgets()->sole()->width)->toBe(WidgetWidth::OneThird);
    });

    it('refuses a widget that is not in the registry', function () {
        $dashboard = Dashboard::factory()->for(dashboardOwner())->create();

        $this->actingAs($dashboard->user)
            ->postJson(route('dashboards.widgets.store', $dashboard), ['system_widget' => 'crypto-ticker'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('system_widget');
    });

    it('refuses the same system widget twice on one dashboard', function () {
        $dashboard = Dashboard::factory()->for(dashboardOwner())->create();
        DashboardWidget::factory()->for($dashboard)->system(SystemWidget::TopLabels)->create();

        $this->actingAs($dashboard->user)
            ->postJson(route('dashboards.widgets.store', $dashboard), ['system_widget' => 'top-labels'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('system_widget');
    });

    it('refuses a size the widget does not admit', function (string $systemWidget, array $size) {
        $dashboard = Dashboard::factory()->for(dashboardOwner())->create();

        $this->actingAs($dashboard->user)
            ->postJson(route('dashboards.widgets.store', $dashboard), ['system_widget' => $systemWidget, ...$size])
            ->assertUnprocessable();

        expect($dashboard->widgets()->count())->toBe(0);
    })->with([
        'a third of the row for the net worth chart' => ['net-worth', ['width' => '1/3', 'height' => 'l']],
        'a large cashflow card' => ['cashflow', ['width' => '1/1', 'height' => 'l']],
        'a width outside the six sizes' => ['top-labels', ['width' => '1/4', 'height' => 'm']],
        'a height outside the six sizes' => ['top-labels', ['width' => '1/1', 'height' => 'xl']],
    ]);

    it('resizes a widget between the sizes it admits', function () {
        $widget = DashboardWidget::factory()->for(Dashboard::factory()->for(dashboardOwner()))->system(SystemWidget::TopCategories)->create();

        $this->actingAs($widget->dashboard->user)
            ->patch(route('dashboards.widgets.update', [$widget->dashboard, $widget]), ['width' => '1/2', 'height' => 'm'])
            ->assertRedirect();

        expect($widget->fresh())
            ->width->toBe(WidgetWidth::Half)
            ->height->toBe(WidgetHeight::Medium);
    });

    it('refuses to resize a widget to a size it does not admit', function () {
        $widget = DashboardWidget::factory()->for(Dashboard::factory()->for(dashboardOwner()))->system(SystemWidget::Cashflow)->create();

        $this->actingAs($widget->dashboard->user)
            ->patchJson(route('dashboards.widgets.update', [$widget->dashboard, $widget]), ['width' => '1/1', 'height' => 'l'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('width');

        expect($widget->fresh()->height)->toBe(WidgetHeight::Medium);
    });

    it('reorders and removes widgets', function () {
        $dashboard = Dashboard::factory()->for(dashboardOwner())->create();
        [$first, $second] = DashboardWidget::factory()->for($dashboard)->count(2)
            ->sequence(['system_widget' => SystemWidget::Cashflow, 'position' => 0], ['system_widget' => SystemWidget::TopLabels, 'position' => 1])
            ->create();

        $this->actingAs($dashboard->user)
            ->patch(route('dashboards.widgets.reorder', $dashboard), ['ids' => [$second->id, $first->id]])
            ->assertRedirect();
        expect($second->fresh()->position)->toBe(0)->and($first->fresh()->position)->toBe(1);

        $this->actingAs($dashboard->user)
            ->delete(route('dashboards.widgets.destroy', [$dashboard, $first]))
            ->assertRedirect();
        expect($dashboard->widgets()->pluck('id')->all())->toBe([$second->id]);
    });
});

describe('ownership', function () {
    it('keeps another user\'s dashboard out of reach', function (string $method, string $route, array $payload, bool $onWidget) {
        $foreign = Dashboard::factory()->create(['name' => 'Theirs']);
        $widget = DashboardWidget::factory()->for($foreign)->system(SystemWidget::TopLabels)->create();
        $parameters = $onWidget ? [$foreign, $widget] : [$foreign];

        $this->actingAs(dashboardOwner())
            ->json($method, route($route, $parameters), $payload)
            ->assertForbidden();

        expect($foreign->fresh()->name)->toBe('Theirs')
            ->and($widget->fresh())->not->toBeNull()
            ->and($widget->fresh()->width)->toBe(WidgetWidth::Full);
    })->with([
        'rename' => ['PATCH', 'dashboards.update', ['name' => 'Mine now'], false],
        'delete' => ['DELETE', 'dashboards.destroy', [], false],
        'add a widget' => ['POST', 'dashboards.widgets.store', ['system_widget' => 'cashflow'], false],
        'reorder widgets' => ['PATCH', 'dashboards.widgets.reorder', ['ids' => []], false],
        'resize a widget' => ['PATCH', 'dashboards.widgets.update', ['width' => '1/2', 'height' => 'm'], true],
        'remove a widget' => ['DELETE', 'dashboards.widgets.destroy', [], true],
    ]);

    it('refuses another user\'s dashboards in a reorder', function () {
        $user = dashboardOwner();
        $foreign = Dashboard::factory()->create(['position' => 5]);

        $this->actingAs($user)
            ->patchJson(route('dashboards.reorder'), ['ids' => [$foreign->id]])
            ->assertUnprocessable();

        expect($foreign->fresh()->position)->toBe(5);
    });

    it('does not reach a widget through another dashboard', function () {
        $dashboard = Dashboard::factory()->for(dashboardOwner())->create();
        $foreignWidget = DashboardWidget::factory()->create();

        $this->actingAs($dashboard->user)
            ->delete(route('dashboards.widgets.destroy', [$dashboard, $foreignWidget]))
            ->assertNotFound();

        expect($foreignWidget->fresh())->not->toBeNull();
    });

    it('does not open another user\'s dashboard on the dashboard page', function () {
        $foreign = Dashboard::factory()->create();
        DashboardWidget::factory()->for($foreign)->create();

        $this->actingAs(dashboardOwner())
            ->get(route('dashboard', ['dashboard' => $foreign->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('selectedDashboard', null));
    });
});

describe('paid plan and shared accounts', function () {
    beforeEach(function () {
        config([
            'subscriptions.enabled' => true,
            'app.demo.email' => 'demo@example.test',
            'app.press.email' => 'press@example.test',
        ]);
    });

    it('sends a user without a plan to the paywall, like the rest of the app', function () {
        $user = dashboardOwner();

        $this->actingAs($user)
            ->post(route('dashboards.store'), ['name' => 'Spending'])
            ->assertRedirect(route('subscribe'));

        expect($user->dashboards()->count())->toBe(0);
    });

    it('keeps a free-plan user who gets past the paywall from building dashboards', function () {
        $user = User::factory()->onboarded()->create(['paywall_seen_at' => now()]);
        $dashboard = Dashboard::factory()->for($user)->create(['name' => 'Kept']);

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->actingAs($user)->post(route('dashboards.store'), ['name' => 'Spending'])->assertForbidden();
        $this->actingAs($user)->patch(route('dashboards.update', $dashboard), ['name' => 'Changed'])->assertForbidden();

        expect($user->dashboards()->pluck('name')->all())->toBe(['Kept']);
    });

    it('lets a user without the plan clean up but opens the system dashboard by default', function () {
        $user = User::factory()->onboarded()->create(['paywall_seen_at' => now()]);
        $dashboard = Dashboard::factory()->for($user)->create();
        $widget = DashboardWidget::factory()->for($dashboard)->create();
        $user->forceFill(['default_dashboard_id' => $dashboard->id])->save();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('selectedDashboard', null));

        $this->actingAs($user)->delete(route('dashboards.widgets.destroy', [$dashboard, $widget]))->assertRedirect();
        $this->actingAs($user)->delete(route('dashboards.destroy', $dashboard))->assertRedirect();

        expect(Dashboard::find($dashboard->id))->toBeNull();
    });

    it('keeps the shared demo and press accounts from writing', function (string $email) {
        $user = User::factory()->onboarded()->subscribed()->create(['email' => $email]);
        $dashboard = Dashboard::factory()->for($user)->create(['name' => 'Shared']);

        $this->actingAs($user)->post(route('dashboards.store'), ['name' => 'Spending'])->assertSessionHasErrors('demo');
        $this->actingAs($user)->patch(route('dashboards.update', $dashboard), ['name' => 'Changed'])->assertSessionHasErrors('demo');
        $this->actingAs($user)->delete(route('dashboards.destroy', $dashboard))->assertSessionHasErrors('demo');
        $this->actingAs($user)->post(route('dashboards.widgets.store', $dashboard), ['system_widget' => 'cashflow'])->assertSessionHasErrors('demo');
        $this->actingAs($user)->put(route('dashboards.default.update'), ['dashboard_id' => $dashboard->id])->assertSessionHasErrors('demo');

        expect($user->dashboards()->pluck('name')->all())->toBe(['Shared'])
            ->and($dashboard->widgets()->count())->toBe(0)
            ->and($user->fresh()->default_dashboard_id)->toBeNull();
    })->with(['demo' => 'demo@example.test', 'press' => 'press@example.test']);

    it('lets a subscribed user build dashboards', function () {
        $user = User::factory()->onboarded()->subscribed()->create();

        $this->actingAs($user)->post(route('dashboards.store'), ['name' => 'Spending'])->assertRedirect();

        expect($user->dashboards()->count())->toBe(1);
    });
});

describe('account deletion', function () {
    it('takes the user\'s dashboards and widgets with it', function () {
        $dashboard = Dashboard::factory()->for(dashboardOwner())->create();
        $widget = DashboardWidget::factory()->for($dashboard)->create();
        $user = $dashboard->user;
        $user->forceFill(['default_dashboard_id' => $dashboard->id])->save();

        $user->forceDelete();

        expect(Dashboard::find($dashboard->id))->toBeNull()
            ->and(DashboardWidget::find($widget->id))->toBeNull();
    });
});

describe('dashboard page', function () {
    it('lists the user\'s dashboards and keeps the system dashboard on screen by default', function () {
        $user = dashboardOwner();
        $second = Dashboard::factory()->for($user)->create(['name' => 'B', 'position' => 1]);
        $first = Dashboard::factory()->for($user)->create(['name' => 'A', 'position' => 0]);
        Dashboard::factory()->create(['name' => 'Someone else\'s']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('dashboard')
                ->where('dashboards', [
                    ['id' => $first->id, 'name' => 'A', 'position' => 0],
                    ['id' => $second->id, 'name' => 'B', 'position' => 1],
                ])
                ->where('defaultDashboardId', null)
                ->where('selectedDashboard', null));
    });

    it('selects the default dashboard, or the one in the query string, with its widgets', function () {
        $user = dashboardOwner();
        $default = Dashboard::factory()->for($user)->create(['name' => 'Default']);
        $other = Dashboard::factory()->for($user)->create(['name' => 'Other']);
        $user->forceFill(['default_dashboard_id' => $default->id])->save();
        $widget = DashboardWidget::factory()->for($default)->system(SystemWidget::Cashflow)->create(['width' => WidgetWidth::Half]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('defaultDashboardId', $default->id)
                ->where('selectedDashboard', [
                    'id' => $default->id,
                    'name' => 'Default',
                    'widgets' => [[
                        'id' => $widget->id,
                        'system_widget' => 'cashflow',
                        'widget_definition_id' => null,
                        'position' => 0,
                        'width' => '1/2',
                        'height' => 'm',
                        'config' => null,
                    ]],
                ]));

        $this->actingAs($user)
            ->get(route('dashboard', ['dashboard' => $other->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selectedDashboard.id', $other->id)
                ->where('selectedDashboard.widgets', []));

        $other->delete();

        $this->actingAs($user)
            ->get(route('dashboard', ['dashboard' => $other->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('selectedDashboard.id', $default->id));
    });
});
