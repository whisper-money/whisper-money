<?php

namespace App\Enums;

/**
 * The upsell entry point a subscription checkout was started from, used to
 * attribute revenue to each upgrade prompt. The value is carried into Stripe as
 * subscription metadata and persisted onto the local subscription so revenue
 * can be measured per upsell point.
 */
enum UpsellSource: string
{
    case AiCategorization = 'ai_categorization';
    case Connections = 'connections';
    case Accounts = 'accounts';
}
