<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const page = usePage();

const appName = computed(() => page.props.appConfig?.appName ?? 'Lebytek');
const themeColors = computed(() => page.props.appConfig?.themeColors ?? {});
const logoUrl = computed(() => page.props.appConfig?.logoUrl as string | null | undefined);

onMounted(() => {
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/sw.js').catch(() => undefined);
    }
});
</script>

<template>
    <Head :title="appName">
        <meta name="theme-color" :content="page.props.appConfig?.pwaThemeColor ?? '#2563eb'" />
        <link rel="icon" :href="page.props.appConfig?.faviconUrl ?? '/favicon.ico'" />
    </Head>

    <div
        class="min-h-screen flex flex-col items-center justify-center px-6"
        :style="{
            backgroundColor: themeColors.background ?? 'var(--color-background)',
            color: themeColors.foreground ?? 'var(--color-foreground)',
        }"
    >
        <div class="max-w-xl text-center">
            <img
                v-if="logoUrl"
                :src="logoUrl"
                :alt="appName"
                class="mx-auto mb-6 h-24 w-auto object-contain"
            />

            <h1
                class="text-4xl font-bold"
                :style="{ color: themeColors.primary ?? 'var(--color-primary)' }"
            >
                {{ appName }}
            </h1>
            <p class="mt-4 text-lg text-red-600">
                {{ t('welcome.tagline') }}
            </p>
            <div class="mt-8 flex items-center justify-center gap-4">
                <Link
                    :href="route('admin.login')"
                    class="rounded-md px-5 py-2 text-sm font-semibold text-white"
                    :style="{ backgroundColor: themeColors.primary ?? 'var(--color-primary)' }"
                >
                    {{ t('nav.access_panel') }}
                </Link>
            </div>
        </div>
    </div>
</template>
