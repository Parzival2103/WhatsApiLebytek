<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps<{
    dashboardWidgets?: Array<{
        key: string;
        permission: string;
        component: string | null;
        data: Record<string, unknown>;
    }>;
}>();
</script>

<template>
    <Head title="Dashboard" />

    <AdminLayout>
        <template #header>
            <h2
                class="text-xl font-semibold leading-tight text-gray-800"
            >
                Dashboard
            </h2>
        </template>

        <div class="grid gap-4 md:grid-cols-2">
            <div
                v-for="widget in dashboardWidgets ?? []"
                :key="widget.key"
                class="overflow-hidden rounded-lg bg-white p-6 shadow-sm"
            >
                <h3 class="text-lg font-medium text-gray-900">
                    {{ widget.data.title ?? widget.key }}
                </h3>
                <p v-if="widget.data.message" class="mt-2 text-sm text-gray-600">
                    {{ widget.data.message }}
                </p>
                <p v-if="widget.data.status" class="mt-2 text-sm text-gray-600">
                    Estado: {{ widget.data.status }}
                </p>
            </div>
        </div>
    </AdminLayout>
</template>
