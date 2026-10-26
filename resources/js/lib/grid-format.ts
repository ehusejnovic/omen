export function priceDecimals(tickSize: number): number {
    return tickSize > 0 ? Math.max(0, Math.round(-Math.log10(tickSize))) : 4;
}

export function formatPrice(value: number, tickSize: number): string {
    const decimals = priceDecimals(tickSize);

    return value.toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });
}

export function formatUsdt(value: number): string {
    return value.toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

export function formatPct(value: number, digits = 2, signed = false): string {
    const formatted = `${value.toFixed(digits)}%`;

    return signed && value > 0 ? `+${formatted}` : formatted;
}

export function formatCompact(value: number): string {
    return Intl.NumberFormat('en-US', {
        notation: 'compact',
        maximumFractionDigits: 1,
    }).format(value);
}

export function formatRelative(iso: string): string {
    const minutes = Math.round((Date.now() - new Date(iso).getTime()) / 60000);

    if (minutes < 1) {
        return 'just now';
    }

    if (minutes < 60) {
        return `${minutes} min ago`;
    }

    const hours = Math.floor(minutes / 60);

    return `${hours} h ${minutes % 60} min ago`;
}

export function formatDuration(seconds: number): string {
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);

    if (days > 0) {
        return `${days}d ${hours}h`;
    }

    if (hours > 0) {
        return `${hours}h ${minutes}m`;
    }

    return `${minutes}m`;
}

export function formatDateTime(iso: string): string {
    return new Date(iso).toLocaleString('en-GB', {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/**
 * Convert an ISO timestamp to the value a datetime-local input expects, in the viewer's timezone.
 */
export function toLocalInput(iso: string): string {
    const date = new Date(iso);
    const offsetMs = date.getTimezoneOffset() * 60000;

    return new Date(date.getTime() - offsetMs).toISOString().slice(0, 16);
}

/**
 * Convert a datetime-local input value (viewer's timezone) to an ISO timestamp, or null when empty.
 */
export function fromLocalInput(value: string): string | null {
    return value === '' ? null : new Date(value).toISOString();
}
