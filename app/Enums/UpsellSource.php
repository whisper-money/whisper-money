<?php

namespace App\Enums;

/**
 * The upsell entry point a subscription checkout was started from, used to
 * attribute revenue to each upgrade prompt. The value is carried into Stripe as
 * subscription metadata and persisted onto the local subscription so revenue
 * can be measured per upsell point.
 *
 * Mirrored on the frontend by the UpsellSource union in
 * resources/js/components/subscription/upgrade-dialog.tsx — keep both in sync
 * when adding a point (an unknown value is silently dropped by tryFrom()).
 */
enum UpsellSource: string
{
    case AiCategorization = 'ai_categorization';
    case Connections = 'connections';
    case Accounts = 'accounts';
}
