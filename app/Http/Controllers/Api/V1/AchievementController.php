<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\V1\AchievementsResource;
use App\Services\Achievements\Progress;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group('Achievements', 'A medal (an achievement) records a milestone you reached: a visit or saving streak, a net worth or savings rate level, transactions recorded, data kept tidy. Medals cover your whole account, not a space.', weight: 110)]
class AchievementController extends Controller
{
    /**
     * List achievements
     *
     * The whole medal catalog, grouped into tracks, earned or not. On each
     * track, the first medal you have not earned yet is `next` and comes named,
     * often with how far along you are; the ones past it are `locked` and stay
     * silhouettes, with no name, icon or figure. The catalog is small and
     * fixed, so it is not paginated.
     */
    public function index(Request $request, Progress $progress): AchievementsResource
    {
        return new AchievementsResource($progress->for($this->user($request)));
    }
}
