// Display helpers: number and date formats follow the UI language.

import { getLocale } from '@/i18n';

/** Intl locale for the current UI language (RO: 1.234,56 / EN: 1,234.56). */
const intlLocale = (): string => (getLocale() === 'ro' ? 'ro-RO' : 'en-GB');

export function formatNumber(
    value: number | null | undefined,
    decimals = 0,
): string {
    return new Intl.NumberFormat(intlLocale(), {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }).format(value ?? 0);
}

export function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat(intlLocale(), {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(new Date(value));
}

export function formatDateTime(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat(intlLocale(), {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
}

/** "acum 5 minute" / "5 minutes ago". */
export function timeAgo(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const seconds = Math.round((new Date(value).getTime() - Date.now()) / 1000);
    const units: [Intl.RelativeTimeFormatUnit, number][] = [
        ['year', 31536000],
        ['month', 2592000],
        ['week', 604800],
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ];
    const format = new Intl.RelativeTimeFormat(intlLocale(), {
        numeric: 'auto',
    });

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return format.format(Math.round(seconds / size), unit);
        }
    }

    return format.format(Math.min(0, seconds), 'second');
}

/** 75 → "1m 15s", 8 → "8s". */
export function formatDuration(seconds: number | null | undefined): string {
    if (seconds === null || seconds === undefined) {
        return '—';
    }

    const minutes = Math.floor(seconds / 60);
    const rest = seconds % 60;

    if (minutes >= 60) {
        return `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
    }

    return minutes > 0 ? `${minutes}m ${rest}s` : `${rest}s`;
}

/** 1536 → "1,5 KB". */
export function formatBytes(bytes: number | null | undefined): string {
    if (!bytes) {
        return '0 B';
    }

    const units = ['B', 'KB', 'MB', 'GB'];
    const index = Math.min(
        units.length - 1,
        Math.floor(Math.log(bytes) / Math.log(1024)),
    );

    return `${formatNumber(bytes / 1024 ** index, index === 0 ? 0 : 1)} ${units[index]}`;
}

/** 980 (MB) → "980 MB", 2048 → "2 GB". */
export function formatMegabytes(
    mb: number | string | null | undefined,
): string {
    const value = Number(mb);

    if (!mb || Number.isNaN(value)) {
        return '—';
    }

    return value >= 1024
        ? `${formatNumber(value / 1024, value % 1024 === 0 ? 0 : 1)} GB`
        : `${formatNumber(value)} MB`;
}

export function initials(name: string): string {
    return (
        name
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((part) => part[0]?.toUpperCase() ?? '')
            .join('') || 'A'
    );
}
