<?php

namespace App\Exceptions\Banking;

use InvalidArgumentException;

/**
 * The credentials authenticate, but the key lacks a permission the sync needs.
 * Its message is user-facing: it names the permission to enable.
 */
class MissingProviderPermissionException extends InvalidArgumentException {}
