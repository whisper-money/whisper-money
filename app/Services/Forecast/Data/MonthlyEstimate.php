<?php

namespace App\Services\Forecast\Data;

use App\Enums\ForecastEstimateKind;

/**
 * Money a parent category is expected to move on an account in a `Y-m`
 * month beyond its concrete lines, in positive minor units: spend for an
 * expense estimate, money in for an income one. For the month the
 * projection starts in it is what is left of it.
 *
 * Its timing inside the month: income lands as one lump on `day` (clamped
 * to the month's length and, in the start month, to the days left); spend
 * follows `profile`, the weight of each day of the month (31 entries, the
 * 1st first), the days a short month lacks falling on its last day. With
 * neither, or with no weight left on the days the month covers, it is
 * spread evenly. Several estimates for the same account and month add up.
 *
 * `kind` and `categoryId` also reach the client engine through toArray(),
 * which edits a category's own estimate by them.
 *
 * @api
 */
final readonly class MonthlyEstimate
{
    /**
     * @param  list<int>|null  $profile
     */
    public function __construct(
        public string $accountId,
        public string $month,
        public int $amount,
        public ForecastEstimateKind $kind = ForecastEstimateKind::Expense,
        public ?string $categoryId = null,
        public ?int $day = null,
        public ?array $profile = null,
    ) {}

    public function isIncome(): bool
    {
        return $this->kind === ForecastEstimateKind::Income;
    }

    public function withAmount(int $amount): self
    {
        return new self($this->accountId, $this->month, $amount, $this->kind, $this->categoryId, $this->day, $this->profile);
    }

    /**
     * @param  array{accountId: string, month: string, amount: int, kind?: string, categoryId?: string|null, day?: int|null, profile?: list<int>|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['accountId'],
            $data['month'],
            $data['amount'],
            ForecastEstimateKind::from($data['kind'] ?? ForecastEstimateKind::Expense->value),
            $data['categoryId'] ?? null,
            $data['day'] ?? null,
            $data['profile'] ?? null,
        );
    }

    /**
     * The shape resources/js/lib/forecast/project.ts takes.
     *
     * @return array{accountId: string, month: string, amount: int, kind: string, categoryId: string|null, day: int|null, profile: list<int>|null}
     */
    public function toArray(): array
    {
        return [
            'accountId' => $this->accountId,
            'month' => $this->month,
            'amount' => $this->amount,
            'kind' => $this->kind->value,
            'categoryId' => $this->categoryId,
            'day' => $this->day,
            'profile' => $this->profile,
        ];
    }
}
