/**
 * Display formatters for Indonesian locale.
 *
 * Monetary values arrive from the backend as whole rupiah integers (ADR-008),
 * so these helpers never deal with decimals.
 */

const RUPIAH = new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
});

const NUMBER = new Intl.NumberFormat('id-ID');

const DATE = new Intl.DateTimeFormat('id-ID', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
});

const DATE_TIME = new Intl.DateTimeFormat('id-ID', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
});

const DATE_SHORT = new Intl.DateTimeFormat('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
});

export function formatRupiah(amount: number): string {
    return RUPIAH.format(amount);
}

export function formatNumber(value: number): string {
    return NUMBER.format(value);
}

export function formatPercent(value: number, fractionDigits = 0): string {
    return `${NUMBER.format(Number(value.toFixed(fractionDigits)))}%`;
}

/** Converts grams to a readable weight, e.g. 1500 -> "1,5 kg". */
export function formatWeight(gram: number): string {
    if (gram < 1000) {
        return `${NUMBER.format(gram)} g`;
    }

    return `${NUMBER.format(Number((gram / 1000).toFixed(2)))} kg`;
}

export function formatDate(value: string | Date | null | undefined): string {
    if (!value) {
        return '-';
    }

    return DATE.format(new Date(value));
}

export function formatDateTime(
    value: string | Date | null | undefined,
): string {
    if (!value) {
        return '-';
    }

    return DATE_TIME.format(new Date(value));
}

export function formatDateShort(
    value: string | Date | null | undefined,
): string {
    if (!value) {
        return '-';
    }

    return DATE_SHORT.format(new Date(value));
}

/**
 * A length of time given in minutes, in the largest units that fit:
 * "15 menit", "2 jam", "1 hari", "1 hari 6 jam".
 */
export function formatDuration(minutes: number): string {
    const days = Math.floor(minutes / 1440);
    const hours = Math.floor((minutes % 1440) / 60);
    const rest = minutes % 60;

    const parts = [
        days > 0 ? `${days} hari` : null,
        hours > 0 ? `${hours} jam` : null,
        rest > 0 || (days === 0 && hours === 0) ? `${rest} menit` : null,
    ].filter((part): part is string => part !== null);

    return parts.join(' ');
}

/** Relative time in Indonesian, e.g. "3 jam lalu". */
export function formatRelative(
    value: string | Date | null | undefined,
): string {
    if (!value) {
        return '-';
    }

    const seconds = Math.round((Date.now() - new Date(value).getTime()) / 1000);

    const units: Array<[label: string, seconds: number]> = [
        ['tahun', 31536000],
        ['bulan', 2592000],
        ['hari', 86400],
        ['jam', 3600],
        ['menit', 60],
    ];

    for (const [label, unitSeconds] of units) {
        const amount = Math.floor(Math.abs(seconds) / unitSeconds);

        if (amount >= 1) {
            return seconds >= 0
                ? `${amount} ${label} lalu`
                : `dalam ${amount} ${label}`;
        }
    }

    return 'baru saja';
}

const COMPACT = new Intl.NumberFormat('id-ID', {
    notation: 'compact',
    maximumFractionDigits: 1,
});

/** Short amounts for chart axes, e.g. 1250000 -> "Rp1,3 jt". */
export function formatRupiahCompact(amount: number): string {
    return `Rp${COMPACT.format(amount)}`;
}
