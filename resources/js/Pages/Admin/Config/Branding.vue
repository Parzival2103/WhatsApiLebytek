<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const props = defineProps<{
    appName: string;
    pwaThemeColor: string;
    pwaBackgroundColor: string;
    logoArchivoId: number | null;
    faviconArchivoId: number | null;
    pwaIconArchivoId: number | null;
    logoUrl?: string | null;
    faviconUrl?: string | null;
    pwaIconUrl?: string | null;
}>();

const { t } = useI18n();

const form = useForm({
    appName: props.appName,
    pwaThemeColor: props.pwaThemeColor,
    pwaBackgroundColor: props.pwaBackgroundColor,
    logo: null as File | null,
    favicon: null as File | null,
    pwaIcon: null as File | null,
});

const onFile = (field: 'logo' | 'favicon' | 'pwaIcon', event: Event) => {
    const input = event.target as HTMLInputElement;
    form[field] = input.files?.[0] ?? null;
};

const submit = () => {
    form.post(route('admin.config.branding.update'), { forceFormData: true });
};
</script>

<template>
    <Head :title="t('config.branding_title')" />

    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800">{{ t('config.branding_title') }}</h2>
        </template>

        <div class="max-w-xl rounded-lg bg-white p-6 shadow-sm">
            <p class="mb-4 text-sm text-gray-600">{{ t('config.branding_help') }}</p>

            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <InputLabel for="appName" :value="t('config.app_name')" />
                    <TextInput id="appName" v-model="form.appName" class="mt-1 block w-full" />
                </div>

                <div>
                    <InputLabel for="pwaThemeColor" :value="t('config.pwa_theme_color')" />
                    <TextInput id="pwaThemeColor" v-model="form.pwaThemeColor" class="mt-1 block w-full" />
                </div>

                <div>
                    <InputLabel for="pwaBackgroundColor" :value="t('config.pwa_background_color')" />
                    <TextInput id="pwaBackgroundColor" v-model="form.pwaBackgroundColor" class="mt-1 block w-full" />
                </div>

                <div>
                    <InputLabel for="logo" :value="t('config.logo')" />
                    <img v-if="logoUrl" :src="logoUrl" alt="" class="mb-2 h-12 w-auto" />
                    <input id="logo" type="file" accept="image/png,image/jpeg,image/webp" @change="onFile('logo', $event)" />
                </div>

                <div>
                    <InputLabel for="favicon" :value="t('config.favicon')" />
                    <img v-if="faviconUrl" :src="faviconUrl" alt="" class="mb-2 h-8 w-8" />
                    <input id="favicon" type="file" accept="image/png,image/jpeg,image/webp" @change="onFile('favicon', $event)" />
                </div>

                <div>
                    <InputLabel for="pwaIcon" :value="t('config.pwa_icon')" />
                    <img v-if="pwaIconUrl" :src="pwaIconUrl" alt="" class="mb-2 h-12 w-12" />
                    <input id="pwaIcon" type="file" accept="image/png,image/jpeg,image/webp" @change="onFile('pwaIcon', $event)" />
                </div>

                <PrimaryButton :disabled="form.processing">{{ t('config.save') }}</PrimaryButton>
            </form>
        </div>
    </AdminLayout>
</template>
