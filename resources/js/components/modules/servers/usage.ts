// RAM / disk figures of a server, read from the last check (server.info, values in MB).
import type { ServerInfo } from '@/types';

/** Below this much RAM, building the front-end on the server is risky (npm run build gets killed). */
export const LOW_MEMORY_MB = 2000;

export type Usage = {
    used: number;
    total: number;
    /** 0–100 */
    percent: number;
    /** What is still free (MB). */
    free: number;
};

/** "1963" → 1963, "" / "?" / undefined → null. */
export function toNumber(
    value: string | number | null | undefined,
): number | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const number = Number(value);

    return Number.isFinite(number) ? number : null;
}

function usage(used: number | null, total: number | null): Usage | null {
    if (total === null || total <= 0 || used === null) {
        return null;
    }

    const clamped = Math.min(Math.max(used, 0), total);

    return {
        used: clamped,
        total,
        percent: Math.round((clamped / total) * 100),
        free: total - clamped,
    };
}

/** RAM really in use = total − available (the page cache can be freed, so it does not count). */
export function memoryUsage(info: ServerInfo | null | undefined): Usage | null {
    const total = toNumber(info?.mem_total);
    const available = toNumber(info?.mem_available);
    const used =
        total !== null && available !== null
            ? total - available
            : toNumber(info?.mem_used);

    return usage(used, total);
}

export function swapUsage(info: ServerInfo | null | undefined): Usage | null {
    return usage(toNumber(info?.swap_used), toNumber(info?.swap_total));
}

export function diskUsage(info: ServerInfo | null | undefined): Usage | null {
    return usage(toNumber(info?.disk_used), toNumber(info?.disk_total));
}

export function isLowMemory(memoryMb: number | null | undefined): boolean {
    return !!memoryMb && memoryMb < LOW_MEMORY_MB;
}

/** Bar color: green, amber from 75 %, red from 90 %. */
export function usageColor(percent: number): string {
    if (percent >= 90) {
        return 'bg-rose-500';
    }

    return percent >= 75 ? 'bg-amber-500' : 'bg-emerald-500';
}
