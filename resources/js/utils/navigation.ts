// Main menu, shared by the top bar (desktop) and the side drawer (mobile).
import { FolderGit2, LayoutDashboard, Server } from '@lucide/vue';
import type { Component } from 'vue';
import { markRaw } from 'vue';
import { dashboard } from '@/routes';
import projects from '@/routes/projects';
import servers from '@/routes/servers';

export type NavItem = {
    /** English label, translated with $t() where it is shown. */
    label: string;
    href: string;
    icon: Component;
    /** URL path that marks the item as active. */
    match: string;
    exact?: boolean;
    /** Also active on these paths (e.g. a deployment belongs to Projects). */
    also?: string[];
    children?: NavItem[];
};

export function navigationItems(): NavItem[] {
    return [
        {
            label: 'Dashboard',
            href: dashboard().url,
            icon: markRaw(LayoutDashboard),
            match: '/',
            exact: true,
        },
        {
            label: 'Projects',
            href: projects.index().url,
            icon: markRaw(FolderGit2),
            match: '/projects',
            also: ['/deployments'],
        },
        {
            label: 'Servers',
            href: servers.index().url,
            icon: markRaw(Server),
            match: '/servers',
        },
    ];
}

export function isActive(item: NavItem, url: string): boolean {
    const path = url.split('?')[0];
    const matches = (prefix: string) =>
        path === prefix || path.startsWith(`${prefix}/`);

    if (item.exact) {
        return path === item.match;
    }

    return matches(item.match) || (item.also ?? []).some(matches);
}
