// Display helpers for the Buy credits preview. Integer maths only (BigInt),
// never floats; the server parses and validates the real amount.

const NANOS_PER_CENT = 10_000_000n;

/** "12" or "12.50" → 1250n cents; anything else → null. */
export function parseDollars(input: string): bigint | null {
    const match = input.trim().match(/^(\d{1,4})(?:\.(\d{1,2}))?$/);

    if (!match) {
        return null;
    }

    return BigInt(match[1]) * 100n + BigInt((match[2] ?? '').padEnd(2, '0'));
}

/** Nanodollars → whole cents, rounded half up (away from zero). */
export function nanosToCents(nanos: number): bigint {
    const n = BigInt(nanos);
    const half = NANOS_PER_CENT / 2n;

    return n >= 0n
        ? (n + half) / NANOS_PER_CENT
        : -((-n + half) / NANOS_PER_CENT);
}

/** 1250n → "$12.50", -7n → "-$0.07". */
export function formatCents(cents: bigint): string {
    const negative = cents < 0n;
    const abs = negative ? -cents : cents;
    const fraction = (abs % 100n).toString().padStart(2, '0');

    return `${negative ? '-' : ''}$${abs / 100n}.${fraction}`;
}
