/** What decides which settings pages a reader gets. */
export interface SettingsVisibilityInput {
    subscriptionsEnabled: boolean;
    isDemoAccount: boolean;
    isSharedAccount: boolean;
    /** The import page is shown: eligible, or an import can still be undone. */
    showFullImport: boolean;
}

/** The settings pages that are not offered to every reader. */
export interface SettingsVisibility {
    aiConnector: boolean;
    api: boolean;
    userAccount: boolean;
    notifications: boolean;
    managePlan: boolean;
    fullImport: boolean;
    deleteAccount: boolean;
}

/**
 * The one place that decides which conditional settings pages a reader sees,
 * read by the settings navigation and by the user menu so the two never offer
 * different pages.
 */
export function settingsVisibility({
    subscriptionsEnabled,
    isDemoAccount,
    isSharedAccount,
    showFullImport,
}: SettingsVisibilityInput): SettingsVisibility {
    return {
        // Demo-only: the press account is shared too, but connecting an
        // assistant to it is the whole reason it exists.
        aiConnector: !isDemoAccount,
        // Both shared accounts: the API refuses them, so they get no tokens.
        api: !isSharedAccount,
        userAccount: !isSharedAccount,
        notifications: !isSharedAccount,
        managePlan: subscriptionsEnabled && !isSharedAccount,
        // Kept out of the way on purpose: it is for moving in, not for every
        // day. Shown while the import window is open, and for as long as an
        // import can still be undone after it.
        fullImport: showFullImport && !isSharedAccount,
        deleteAccount: !isSharedAccount,
    };
}
