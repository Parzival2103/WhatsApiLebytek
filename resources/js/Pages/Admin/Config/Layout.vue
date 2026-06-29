<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const props = defineProps<{
    layoutMode: 'top' | 'side';
}>();

const { t } = useI18n();

const form = useForm({
    layoutMode: props.layoutMode,
});

const submit = () => {
    form.put(route('admin.config.layout.update'));
};
</script>

<template>
    <Head :title="t('config.layout_title')" />

    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800">{{ t('config.layout_title') }}</h2>
        </template>

        <div class="max-w-xl rounded-lg bg-white p-6 shadow-sm">
            <p class="mb-4 text-sm text-gray-600">{{ t('config.layout_help') }}</p>

            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ t('config.layout_mode') }}</label>
                    <select v-model="form.layoutMode" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                        <option value="side">{{ t('config.mode_side') }}</option>
                        <option value="top">{{ t('config.mode_top') }}</option>
                    </select>
                </div>

                <PrimaryButton :disabled="form.processing">{{ t('config.save') }}</PrimaryButton>
            </form>
        </div>
    </AdminLayout>
</template>
