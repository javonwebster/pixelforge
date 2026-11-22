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
    image: {
        /** For the tile: our thumbnail when stored, else Pixelforge's link. */
        url: string | null;
        /** For "open full size". */
        fullUrl: string | null;
        expired: boolean;
        /** Our own copy, which never expires. */
        stored: boolean;
        downloadUrl: string | null;
    } | null;
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

/** Matches App\Services\AdminReport. Amounts are exact 9-decimal strings. */
export type ReportFigures = {
    generations: number;
    spend: string;
    cost: string;
    profit: string;
};

export type ReportDay = ReportFigures & {
    date: string;
    users: (ReportFigures & { id: number; name: string; email: string })[];
    pixelforge: { generations: number; cost: string } | null;
    /** null when Pixelforge's usage couldn't be loaded */
    matches: boolean | null;
};

export type Report = {
    days: ReportDay[];
    totals: ReportFigures & {
        pixelforge: { generations: number; cost: string } | null;
        matches: boolean | null;
    };
    pixelforgeError: string | null;
};
