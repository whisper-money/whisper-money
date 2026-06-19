/**
 * Deterministic sampler keyed on a transaction id, so the AI-categorization
 * upsell sparkle shows on the same rows across reloads instead of flickering and
 * does not appear on every uncategorized row.
 *
 * The share is configurable: `samplePercent` (0-100) comes from the
 * `aiCategorizationUpsellRate` Inertia prop, backed by
 * `ai_categorization.upsell_sample_rate` (env: AI_CATEGORIZATION_UPSELL_SAMPLE_RATE).
 *
 * ponytail: map the id's last byte (0-255) onto the 0-100 threshold. Uniform
 * enough for a cosmetic nudge; swap for a real hash if the split must be exact.
 */
export function showsAiUpsell(
    transactionId: string,
    samplePercent: number,
): boolean {
    const lastByte = parseInt(transactionId.replace(/-/g, '').slice(-2), 16);
    const byte = Number.isNaN(lastByte) ? 256 : lastByte; // 256 → never shows

    return byte < (samplePercent / 100) * 256;
}
