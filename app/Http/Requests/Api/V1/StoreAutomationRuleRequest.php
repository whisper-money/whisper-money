<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesAutomationRuleWrites;
use App\Services\AutomationRules\AutomationRuleWriter;
use Illuminate\Foundation\Http\FormRequest;

class StoreAutomationRuleRequest extends FormRequest
{
    use ValidatesAutomationRuleWrites;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return $this->automationRuleFields(AutomationRuleWriter::createRules(), 'required');
    }
}
