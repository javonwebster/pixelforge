/** An amount from the server: nanodollars plus the two ways to show it. */
export type Money = {
    nanos: number;
    /** Whole cents, what users see: "$0.07" */
    display: string;
    /** All 9 decimals, for a hover title: "$0.072500000" */
    exact: string;
};

export type Balance = {
    available: Money;
    held: Money;
};

/** Matches App\Http\Resources\GenerationResource. */
export type Generation = {
    id: string;
    prompt: string;
    size: string;
    quality: string;
    referenceImages: string[];
    status: string;
    finished: boolean;
    failed: boolean;
    label: string;
    detail: string | null;
    tone: 'waiting' | 'busy' | 'running' | 'succeeded' | 'failed';
    estimatedPrice: Money;
    maximumPrice: Money;
    actualPrice: Money | null;
    image: { url: string | null; expired: boolean } | null;
    retryOf: { id: string; createdAt: string } | null;
    retriedAs: string | null;
    createdAt: string;
};

/** A Laravel resource collection, paginated. */
export type Paginated<T> = {
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        total: number;
    };
    links: {
        prev: string | null;
        next: string | null;
    };
};

/** POST /generate/quote */
export type Quote = {
    estimate: Money;
    maximum: Money;
    available: Money;
    enough: boolean;
    /** Set when the balance can't cover the maximum; the submit's own words. */
    shortfallMessage: string | null;
};
