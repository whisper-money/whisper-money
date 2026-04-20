<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Laravel\Cashier\Cashier;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\PromotionCode;

class GenerateStripePromotionCodesCommand extends Command
{
    private const DEFAULT_COUPON_ID = '0E5fAsXG';

    protected $signature = 'stripe:generate-promotion-codes
        {count : Number of promotion codes to generate}
        {--coupon='.self::DEFAULT_COUPON_ID.' : Stripe coupon ID to attach to each code}';

    protected $description = 'Generate single-use Stripe promotion codes';

    public function handle(): int
    {
        $count = filter_var($this->argument('count'), FILTER_VALIDATE_INT);

        if ($count === false || $count < 1) {
            $this->error('Count must be a positive integer.');

            return self::FAILURE;
        }

        $couponId = (string) $this->option('coupon');
        $generatedCodes = [];
        $rows = [];

        $this->info("Generating {$count} single-use promotion codes for coupon {$couponId}...");

        for ($index = 1; $index <= $count; $index++) {
            try {
                $promotionCode = $this->createPromotionCode($couponId, $generatedCodes);
            } catch (ApiErrorException $exception) {
                $this->error("Failed generating promotion code {$index}: {$exception->getMessage()}");

                return self::FAILURE;
            }

            $generatedCodes[] = $promotionCode->code;
            $rows[] = [$index, $promotionCode->id, $promotionCode->code];
        }

        $this->newLine();
        $this->table(['#', 'Stripe ID', 'Code'], $rows);
        $this->newLine();
        $this->info('Generated '.$count.' promotion code'.($count === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $generatedCodes
     */
    private function createPromotionCode(string $couponId, array $generatedCodes): PromotionCode
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $code = $this->makeCode($generatedCodes);

            try {
                return Cashier::stripe()->promotionCodes->create([
                    'coupon' => $couponId,
                    'code' => $code,
                    'max_redemptions' => 1,
                ]);
            } catch (ApiErrorException $exception) {
                if ($attempt < 5 && $this->isDuplicateCodeError($exception)) {
                    continue;
                }

                throw $exception;
            }
        }

        throw new RuntimeException('Unable to generate a unique promotion code after 5 attempts.');
    }

    /**
     * @param  list<string>  $generatedCodes
     */
    private function makeCode(array $generatedCodes): string
    {
        do {
            $code = 'WM-'.Str::upper(Str::random(10));
        } while (in_array($code, $generatedCodes, true));

        return $code;
    }

    private function isDuplicateCodeError(ApiErrorException $exception): bool
    {
        $message = Str::lower($exception->getMessage());

        return str_contains($message, 'code') && str_contains($message, 'exist');
    }
}
