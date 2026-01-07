<?php

namespace App\Services\Demo;

class DemoLabelsProvider
{
    /**
     * Get demo labels with assignment percentages.
     *
     * @return array<int, array{name: string, color: string, assignment_percentage: int}>
     */
    public function getLabels(): array
    {
        return [
            [
                'name' => 'Essential',
                'color' => 'green',
                'assignment_percentage' => 40,
            ],
            [
                'name' => 'Recurring',
                'color' => 'blue',
                'assignment_percentage' => 25,
            ],
            [
                'name' => 'Review Later',
                'color' => 'amber',
                'assignment_percentage' => 15,
            ],
        ];
    }
}
