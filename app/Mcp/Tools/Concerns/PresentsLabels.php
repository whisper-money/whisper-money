<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Label;

/**
 * The label shape every label tool returns, so list_labels, create_label and
 * update_label all hand the agent the same row, archived flag included.
 */
trait PresentsLabels
{
    /**
     * @return array<string, mixed>
     */
    protected function presentLabel(Label $label): array
    {
        return [
            'id' => $label->id,
            'name' => $label->name,
            'color' => $label->color,
            'archived' => $label->isArchived(),
            'archived_at' => $label->archived_at?->toIso8601String(),
        ];
    }
}
