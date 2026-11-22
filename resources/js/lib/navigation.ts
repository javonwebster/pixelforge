import {
    BookOpen,
    ChartColumn,
    FolderGit2,
    History,
    Sparkles,
} from '@lucide/vue';
import { report } from '@/routes/admin';
import { create, index } from '@/routes/generations';
import type { NavItem } from '@/types';

// Shared by the sidebar and header layouts.
export const mainNavItems: NavItem[] = [
    { title: 'Generate', href: create(), icon: Sparkles },
    { title: 'History', href: index(), icon: History },
];

// Only shown to admins; the route itself also refuses everyone else.
export const adminNavItems: NavItem[] = [
    { title: 'Admin report', href: report(), icon: ChartColumn },
];

export const footerNavItems: NavItem[] = [
    {
        title: 'Source code',
        href: 'https://github.com/javonwebster/pixelforge',
        icon: FolderGit2,
    },
    {
        title: 'Pixelforge API docs',
        href: 'https://pixelforge.promptedit.dev/docs',
        icon: BookOpen,
    },
];
