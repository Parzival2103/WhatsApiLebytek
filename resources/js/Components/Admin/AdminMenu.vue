<script setup lang="ts">
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

export interface AdminMenuNode {
    id: number;
    label: string;
    routeName: string | null;
    icon: string | null;
    children: AdminMenuNode[];
}

const props = defineProps<{
    items: AdminMenuNode[];
    vertical?: boolean;
}>();

const hasChildren = (item: AdminMenuNode): boolean => item.children.length > 0;

const hrefFor = (item: AdminMenuNode): string | null => {
    if (!item.routeName) {
        return null;
    }

    try {
        return route(item.routeName);
    } catch {
        return null;
    }
};

const isActive = (item: AdminMenuNode): boolean => {
    if (!item.routeName) {
        return false;
    }

    return route().current(item.routeName);
};

const linkComponent = computed(() => (props.vertical ? ResponsiveNavLink : NavLink));
</script>

<template>
    <template v-for="item in items" :key="item.id">
        <component
            v-if="hrefFor(item)"
            :is="linkComponent"
            :href="hrefFor(item)!"
            :active="isActive(item)"
        >
            {{ item.label }}
        </component>

        <div
            v-else-if="hasChildren(item)"
            :class="vertical ? 'space-y-1' : 'relative group'"
        >
            <span
                class="block px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500"
            >
                {{ item.label }}
            </span>
            <AdminMenu
                :items="item.children"
                :vertical="vertical"
            />
        </div>
    </template>
</template>

<script lang="ts">
export default {
    name: 'AdminMenu',
};
</script>
