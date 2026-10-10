<?php

namespace App\Notifications;

use App\Models\SavingsGoal;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Rings the bell when a month of a monthly savings goal closes: met by how
 * much, or missed by how much.
 *
 * Database only. The row keeps the figures as they stood at close; the goal's
 * page is where the live ones are, should a late transaction move the month.
 */
class MonthlySavingsGoalClosed extends Notification
{
    /**
     * @param  array<string, mixed>  $month  the closed month's row from MonthlySavingsGoalStats
     */
    public function __construct(
        public SavingsGoal $goal,
        public array $month,
        public int $streak,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'savings_goal_id' => $this->goal->id,
            'goal_name' => $this->goal->name,
            'month' => $this->month['month'],
            'saved' => $this->month['saved'],
            'target' => $this->month['target'],
            'difference' => $this->month['difference'],
            'met' => $this->month['status'] === 'met',
            'streak' => $this->streak,
            'currency_code' => $notifiable->currency_code ?? 'USD',
        ];
    }
}
