/** Runs `callback` only after `wait` ms passed without a new call (e.g. search inputs). */
export function debounce<Args extends unknown[]>(
    callback: (...args: Args) => void,
    wait = 350,
): (...args: Args) => void {
    let timer: ReturnType<typeof setTimeout> | undefined;

    return (...args: Args) => {
        clearTimeout(timer);
        timer = setTimeout(() => callback(...args), wait);
    };
}
