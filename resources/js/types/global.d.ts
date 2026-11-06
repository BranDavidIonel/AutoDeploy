import type { Directive } from 'vue';
import type { CommandOutput } from './models';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            locale: string;
            locales: Record<string, string>;
            auth: { user: { id: number; name: string; email: string } | null };
            queueStuck: boolean;
            [key: string]: unknown;
        };
        flashDataType: {
            success?: string;
            error?: string;
            output?: CommandOutput;
        };
    }
}

declare module 'vue' {
    interface GlobalDirectives {
        vFocus: Directive<HTMLElement, boolean | undefined>;
    }

    interface ComponentCustomProperties {
        /** Translate an English UI text, e.g. $t('Deploy :name', { name }). See resources/js/i18n. */
        $t: (
            key: string,
            replacements?: Record<string, string | number>,
        ) => string;
    }
}

export * from './models';
