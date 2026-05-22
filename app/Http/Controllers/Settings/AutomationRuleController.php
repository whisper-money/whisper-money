<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreAutomationRuleRequest;
use App\Http\Requests\Settings\UpdateAutomationRuleRequest;
use App\Models\AutomationRule;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AutomationRuleController extends Controller
{
    use AuthorizesRequests;

    /**
     * Show the user's automation rules settings page.
     */
    public function index(): Response
    {
        return Inertia::render('settings/automation-rules');
    }

    /**
     * Store a newly created automation rule.
     */
    public function store(StoreAutomationRuleRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $labelIds = $validated['action_label_ids'] ?? [];
        unset($validated['action_label_ids']);

        $rule = auth()->user()->automationRules()->create($validated);

        if (! empty($labelIds)) {
            $rule->labels()->sync($labelIds);
            $rule->touch();
        }

        return back()->with([
            'saved_automation_rule_id' => $rule->id,
            'saved_automation_rule_token' => (string) Str::uuid(),
        ]);
    }

    /**
     * Update the specified automation rule.
     */
    public function update(UpdateAutomationRuleRequest $request, AutomationRule $automationRule): RedirectResponse
    {
        $this->authorize('update', $automationRule);

        $validated = $request->validated();
        $labelIds = $validated['action_label_ids'] ?? [];
        unset($validated['action_label_ids']);

        $automationRule->update($validated);
        $automationRule->labels()->sync($labelIds);
        $automationRule->touch();

        return back()->with([
            'saved_automation_rule_id' => $automationRule->id,
            'saved_automation_rule_token' => (string) Str::uuid(),
        ]);
    }

    /**
     * Soft delete the specified automation rule.
     */
    public function destroy(AutomationRule $automationRule): RedirectResponse
    {
        $this->authorize('delete', $automationRule);

        $automationRule->delete();

        return back();
    }
}
