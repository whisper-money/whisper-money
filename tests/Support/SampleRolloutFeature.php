<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;

/**
 * A plain on/off Pennant feature for testing `feature:enable` and
 * `feature:disable`. The commands only find features under `App\Features`, so
 * a test aliases this class there with {@see self::register()} instead of
 * leaning on a real flag that will be removed once its rollout is over.
 */
class SampleRolloutFeature
{
    public const NAME = 'SampleRolloutFeature';

    public const CLASS_NAME = 'App\\Features\\'.self::NAME;

    public static function register(): void
    {
        if (! class_exists(self::CLASS_NAME)) {
            class_alias(self::class, self::CLASS_NAME);
        }
    }

    public function resolve(?User $user): bool
    {
        return false;
    }
}
