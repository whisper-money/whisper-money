/**
 * The guess slider's range, in dollars. It is a guess, so the steps are coarse
 * on purpose: the point is the gap between this number and the real one, not
 * the number.
 */
const BASE = { min: 300, max: 5000, step: 50, initial: 1200 };

/**
 * Roughly how many units of each currency a dollar of monthly spending is,
 * rounded to 1, 2 or 5 times a power of ten so every bound and step it scales
 * stays a round number. It sizes a slider, not a conversion: a magnitude is
 * all it needs to be right about, so a rupee range starts in the tens of
 * thousands instead of at ₹300, and drifting rates never make it stale.
 * Anything missing reads as dollar-sized.
 */
const UNITS_PER_DOLLAR: Record<string, number> = {
    ARS: 1000,
    AUD: 2,
    BOB: 5,
    BRL: 5,
    CLP: 1000,
    CNY: 5,
    COP: 5000,
    CZK: 20,
    DKK: 5,
    DOP: 50,
    GHS: 10,
    GTQ: 10,
    HKD: 10,
    HNL: 20,
    INR: 50,
    JPY: 100,
    KWD: 0.5,
    MXN: 20,
    NGN: 1000,
    NZD: 2,
    PEN: 5,
    PKR: 200,
    PYG: 5000,
    RSD: 100,
    SAR: 5,
    SEK: 10,
    THB: 50,
    UYU: 50,
    VES: 50,
};

export interface GuessRange {
    min: number;
    max: number;
    step: number;
    initial: number;
}

/** The slider's range in major units of the given currency. */
export function guessRange(currencyCode: string): GuessRange {
    const scale = UNITS_PER_DOLLAR[currencyCode.toUpperCase()] ?? 1;
    // Rounded so a fractional scale can never leave float noise in a bound.
    const scaled = (value: number) => Math.round(value * scale);

    return {
        min: scaled(BASE.min),
        max: scaled(BASE.max),
        step: scaled(BASE.step),
        initial: scaled(BASE.initial),
    };
}
