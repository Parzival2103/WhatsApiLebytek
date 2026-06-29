<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const props = defineProps<{
    themeColors: Record<string, string>;
}>();

const { t } = useI18n();

const form = useForm({
    themeColors: { ...props.themeColors },
});

const submit = () => {
    form.put(route('admin.config.theme.update'));
};
</script>

<template>
    <Head :title="t('config.theme_title')" />

    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800">{{ t('config.theme_title') }}</h2>
        </template>

        <div class="max-w-xl rounded-lg bg-white p-6 shadow-sm">
            <p class="mb-4 text-sm text-gray-600">{{ t('config.theme_help') }}</p>

            <form @submit.prevent="submit" class="space-y-4">
                <div v-for="(value, key) in form.themeColors" :key="key">
                    <InputLabel :for="String(key)" :value="String(key)" />
                    <TextInput
                        :id="String(key)"
                        v-model="form.themeColors[key]"
                        type="text"
                        class="mt-1 block w-full"
                    />
                </div>

                <PrimaryButton :disabled="form.processing">{{ t('config.save') }}</PrimaryButton>
            </form>
        </div>
    </AdminLayout>
</template>
