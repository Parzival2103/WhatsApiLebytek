<script setup lang="ts">
import AdminMenu, { type AdminMenuNode } from '@/Components/Admin/AdminMenu.vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const page = usePage();
const showingNavigationDropdown = ref(false);

const layoutMode = computed(() => page.props.appConfig?.layoutMode ?? 'side');
const adminMenu = computed(() => page.props.adminMenu ?? []);
const themeStyle = computed(() => {
    const colors = page.props.appConfig?.themeColors ?? {};

    return {
        '--color-primary': colors.primary ?? '#2563eb',
        '--color-secondary': colors.secondary ?? '#64748b',
        '--color-accent': colors.accent ?? '#0ea5e9',
        '--color-background': colors.background ?? '#f3f4f6',
        '--color-foreground': colors.foreground ?? '#0f172a',
    } as Record<string, string>;
});
const isSideLayout = computed(() => layoutMode.value === 'side');
</script>

<template>
    <div class="min-h-screen bg-gray-100" :style="themeStyle">
        <div v-if="isSideLayout" class="flex min-h-screen">
            <aside class="hidden w-64 shrink-0 border-r border-gray-200 bg-white md:block">
                <div class="flex h-16 items-center border-b border-gray-100 px-4">
                    <Link :href="route('admin.dashboard')">
                        <ApplicationLogo class="block h-8 w-auto fill-current text-gray-800" />
                    </Link>
                </div>
                <nav class="space-y-1 p-4">
                    <AdminMenu :items="adminMenu" vertical />
                </nav>
            </aside>

            <div class="flex min-w-0 flex-1 flex-col">
                <header class="flex h-16 items-center justify-end border-b border-gray-200 bg-white px-4">
                    <Dropdown align="right" width="48">
                        <template #trigger>
                            <button
                                type="button"
                                class="rounded-md px-3 py-2 text-sm font-medium text-gray-600 hover:text-gray-900"
                            >
                                {{ page.props.auth.user.name }}
                            </button>
                        </template>
                        <template #content>
                            <DropdownLink :href="route('profile.edit')">Profile</DropdownLink>
                            <DropdownLink :href="route('logout')" method="post" as="button">
                                Log Out
                            </DropdownLink>
                        </template>
                    </Dropdown>
                </header>

                <header v-if="$slots.header" class="border-b border-gray-200 bg-white px-6 py-4">
                    <slot name="header" />
                </header>

                <main class="flex-1 p-6">
                    <slot />
                </main>
            </div>
        </div>

        <div v-else>
            <nav class="border-b border-gray-200 bg-white">
                <div class="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
                    <div class="flex items-center gap-8">
                        <Link :href="route('admin.dashboard')">
                            <ApplicationLogo class="block h-8 w-auto fill-current text-gray-800" />
                        </Link>
                        <div class="hidden items-center gap-4 sm:flex">
                            <AdminMenu :items="adminMenu" />
                        </div>
                    </div>
                    <Dropdown align="right" width="48">
                        <template #trigger>
                            <button
                                type="button"
                                class="rounded-md px-3 py-2 text-sm font-medium text-gray-600 hover:text-gray-900"
                            >
                                {{ page.props.auth.user.name }}
                            </button>
                        </template>
                        <template #content>
                            <DropdownLink :href="route('profile.edit')">Profile</DropdownLink>
                            <DropdownLink :href="route('logout')" method="post" as="button">
                                Log Out
                            </DropdownLink>
                        </template>
                    </Dropdown>
                </div>
            </nav>

            <header v-if="$slots.header" class="border-b border-gray-200 bg-white px-6 py-4">
                <slot name="header" />
            </header>

            <main class="mx-auto max-w-7xl p-6">
                <slot />
            </main>
        </div>
    </div>
</template>
