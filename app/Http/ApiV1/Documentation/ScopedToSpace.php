<?php

namespace App\Http\ApiV1\Documentation;

use Attribute;

/**
 * Marks a `/api/v1` endpoint that reads the optional `space` parameter
 * (Controller::space()), so the spec documents it and its 422.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class ScopedToSpace {}
