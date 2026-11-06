// Tiny Laravel-style translator for Vue.
// The key is the English text (like Laravel's __('...')); translations come from the same
// lang/ro.json file the backend uses, so there is one single source of truth.
import type { App, ComponentPublicInstance } from 'vue';
import ro from '../../../lang/ro.json';

export type Replacements = Record<string, string | number>;

const messages: Record<string, Record<string, string>> = { ro };

let currentLocale = 'en';

/** Called by the layout whenever the server sends a (new) locale. */
export function setLocale(locale: string): void {
    currentLocale = locale;
}

export function getLocale(): string {
    return currentLocale;
}

/** Translates "Offer :number was created." with { number: 'OF-1' }. Unknown keys stay in English. */
export function trans(
    key: string,
    replacements: Replacements = {},
    locale: string = currentLocale,
): string {
    let text = messages[locale]?.[key] ?? key;

    // "1 project|:count projects": the first form for a count of 1, the second otherwise.
    if (text.includes('|') && typeof replacements.count === 'number') {
        const forms = text.split('|');
        text = replacements.count === 1 ? forms[0] : forms[forms.length - 1];
    }

    // Longest names first, so ":count" never eats the start of ":countries".
    const names = Object.keys(replacements).sort((a, b) => b.length - a.length);

    for (const name of names) {
        text = text.replaceAll(`:${name}`, String(replacements[name]));
    }

    return text;
}

/** Adds `$t()` to every component (templates and `this.$t()` in the Options API). */
export const i18n = {
    install(app: App): void {
        app.config.globalProperties.$t = function (
            this: ComponentPublicInstance,
            key: string,
            replacements?: Replacements,
        ): string {
            // Reading $page makes every translated text re-render when the language changes.
            return trans(key, replacements, String(this.$page.props.locale));
        };
    },
};
