<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { BarChart3, BookOpen, FolderGit2, LayoutGrid } from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const baseMainNavItems: NavItem[] = [
    {
        title: 'Studio',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Financial Reports',
        href: '/reports',
        icon: BarChart3,
        adminOnly: true,
    },
];

const mainNavItems = computed(() =>
    baseMainNavItems.filter(
        (menuItem) => page.props.auth.user.is_admin || !menuItem.adminOnly,
    ),
);
const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/tmbenhura/PromptEditAssessment',
        icon: FolderGit2,
    },
    {
        title: 'Linked-In Profile',
        href: 'https://zw.linkedin.com/in/taurai-benhura-3329452a',
        icon: BookOpen,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
