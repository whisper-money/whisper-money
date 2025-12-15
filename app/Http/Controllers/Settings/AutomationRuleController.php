<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreAutomationRuleRequest;
use App\Http\Requests\Settings\UpdateAutomationRuleRequest;
use App\Models\AutomationRule;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
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
        $rules = auth()->user()
            ->automationRules()
            ->with(['category:id,name,icon,color', 'labels:id,name,color'])
            ->orderBy('priority')
            ->get(['id', 'title', 'priority', 'rules_json', 'action_category_id', 'action_note', 'action_note_iv']);

        return Inertia::render('settings/automation-rules', [
            'rules' => $rules,
        ]);
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

        return back();
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

        return back();
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
