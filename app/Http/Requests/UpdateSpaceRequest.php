<?php

namespace App\Http\Requests;

use App\Features\Spaces;
use App\Models\Space;
use Illuminate\Foundation\Http\FormRequest;
use Laravel\Pennant\Feature;

class UpdateSpaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $space = $this->route('space');

        return $space instanceof Space
            && ! $space->personal
            && $space->owner_id === $this->user()?->id
            && Feature::for($this->user())->active(Spaces::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
