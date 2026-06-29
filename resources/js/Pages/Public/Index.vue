<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, onMounted } from 'vue';

const page = usePage();

const appName = computed(() => page.props.appConfig?.appName ?? 'Lebytek');
const themeColors = computed(() => page.props.appConfig?.themeColors ?? {});

onMounted(() => {
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/sw.js').catch(() => undefined);
    }
});
</script>

<template>
    <Head :title="appName">
        <meta name="theme-color" :content="page.props.appConfig?.pwaThemeColor ?? '#2563eb'" />
    </Head>

    <div
        class="min-h-screen flex flex-col items-center justify-center px-6"
        :style="{ backgroundColor: themeColors.background ?? '#ffffff', color: themeColors.foreground ?? '#0f172a' }"
    >
        <div class="max-w-xl text-center">
            <h1 class="text-4xl font-bold" :style="{ color: themeColors.primary ?? '#2563eb' }">
                {{ appName }}
            </h1>
            <p class="mt-4 text-lg opacity-80">
                Plataforma administrativa modular Lebytek.
            </p>
            <div class="mt-8 flex items-center justify-center gap-4">
                <Link
                    :href="route('login')"
                    class="rounded-md px-5 py-2 text-sm font-semibold text-white"
                    :style="{ backgroundColor: themeColors.primary ?? '#2563eb' }"
                >
                    Acceder al panel
                </Link>
            </div>
        </div>
    </div>
</template>
