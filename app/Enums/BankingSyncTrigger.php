<?php

namespace App\Enums;

/**
 * What asked for a banking sync, recorded as `metadata['trigger']` on every
 * banking_sync_logs row so "how many manual syncs per user per day" is a query
 * rather than a guess.
 *
 * Scheduled is also the default on the job, so a dispatch site added later and
 * left unlabelled lands in the scheduled bucket rather than inflating whichever
 * on-demand trigger someone is sizing a limit against.
 */
enum BankingSyncTrigger: string
{
    /** The six-hourly sweep, and anything that forgot to say otherwise. */
    case Scheduled = 'scheduled';

    /** The Sync button on the connections page. */
    case Manual = 'manual';

    /** A bank connected for the first time. */
    case Connect = 'connect';

    /** An expired or revoked connection re-authorized by the user. */
    case Reconnect = 'reconnect';

    /** The user finished mapping the bank's accounts onto ours. */
    case AccountMapping = 'account_mapping';

    /** A single account linked to an existing connection. */
    case AccountLinked = 'account_linked';

    /** New API credentials saved for a key-based provider. */
    case CredentialsUpdated = 'credentials_updated';

    /** `banking:sync` run by hand against specific users or connections. */
    case Command = 'command';
}
