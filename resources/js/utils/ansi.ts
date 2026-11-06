// Turns terminal output (colors, progress bars with \r) into safe HTML for the log viewer.

const COLORS: Record<number, string> = {
    30: 'text-slate-400',
    31: 'text-rose-400',
    32: 'text-emerald-400',
    33: 'text-amber-300',
    34: 'text-sky-400',
    35: 'text-fuchsia-400',
    36: 'text-cyan-300',
    37: 'text-slate-100',
    90: 'text-slate-500',
    91: 'text-rose-300',
    92: 'text-emerald-300',
    93: 'text-amber-200',
    94: 'text-sky-300',
    95: 'text-fuchsia-300',
    96: 'text-cyan-200',
    97: 'text-white',
};

const BACKGROUNDS: Record<number, string> = {
    41: 'bg-rose-500/30',
    42: 'bg-emerald-500/30',
    43: 'bg-amber-500/25',
    44: 'bg-sky-500/30',
    45: 'bg-fuchsia-500/30',
    46: 'bg-cyan-500/30',
};

/** Marker lines printed by the deploy scripts for AutoDeploy itself. */
const INTERNAL = /^__AUTODEPLOY_[A-Z_]+__.*$/gm;

function escapeHtml(text: string): string {
    return text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/** Keeps what a terminal would show for lines rewritten with \r (npm / composer progress). */
export function collapseCarriageReturns(text: string): string {
    return text
        .replace(/\r\n/g, '\n')
        .split('\n')
        .map((line) => {
            const parts = line.split('\r');

            return parts[parts.length - 1] || parts.find((p) => p) || '';
        })
        .join('\n');
}

export function cleanOutput(text: string): string {
    return collapseCarriageReturns(text)
        .replace(INTERNAL, '')
        .replace(/\n{3,}/g, '\n\n');
}

export function ansiToHtml(text: string): string {
    const source = cleanOutput(text);
    // eslint-disable-next-line no-control-regex
    const pattern = /\x1b\[([0-9;]*)m/g;
    let html = '';
    let last = 0;
    let classes: string[] = [];

    const flush = (chunk: string) => {
        if (!chunk) {
            return;
        }

        const escaped = escapeHtml(chunk);
        html += classes.length
            ? `<span class="${classes.join(' ')}">${escaped}</span>`
            : escaped;
    };

    for (const match of source.matchAll(pattern)) {
        flush(source.slice(last, match.index));
        last = (match.index ?? 0) + match[0].length;

        const codes = match[1] === '' ? [0] : match[1].split(';').map(Number);

        for (const code of codes) {
            if (code === 0 || code === 22) {
                classes = [];
            } else if (code === 39) {
                classes = classes.filter((c) => !c.startsWith('text-'));
            } else if (code === 49) {
                classes = classes.filter((c) => !c.startsWith('bg-'));
            } else if (code === 1) {
                classes.push('font-semibold');
            } else if (code === 2) {
                classes.push('opacity-60');
            } else if (COLORS[code]) {
                classes = classes.filter((c) => !c.startsWith('text-'));
                classes.push(COLORS[code]);
            } else if (BACKGROUNDS[code]) {
                classes = classes.filter((c) => !c.startsWith('bg-'));
                classes.push(BACKGROUNDS[code]);
            }
        }
    }

    flush(source.slice(last));

    // Highlight the lines AutoDeploy writes itself.
    return html
        .replace(/^(WARNING:.*)$/gm, '<span class="text-amber-300">$1</span>')
        .replace(
            /^(✖.*|ERROR.*)$/gm,
            '<span class="text-rose-400 font-semibold">$1</span>',
        )
        .replace(/^(✔.*)$/gm, '<span class="text-emerald-400">$1</span>')
        .replace(
            /^(■.*)$/gm,
            '<span class="text-amber-300 font-semibold">$1</span>',
        );
}

/** Plain text (for copy / download). */
export function ansiToText(text: string): string {
    // eslint-disable-next-line no-control-regex
    return cleanOutput(text).replace(/\x1b\[[0-9;]*m/g, '');
}
