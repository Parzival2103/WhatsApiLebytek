<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const page = usePage();

const logoUrl = computed(() => page.props.appConfig?.logoUrl as string | null | undefined);
const appName = computed(() => page.props.appConfig?.appName ?? 'Lebytek');

const form = useForm({
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.put(route('admin.password.change.store'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout :logo-url="logoUrl" :app-name="appName">
        <Head :title="t('auth.force_change_title')" />

        <p class="mb-4 text-sm text-gray-600">{{ t('auth.force_change_help') }}</p>

        <form @submit.prevent="submit" class="space-y-4">
            <div>
                <InputLabel for="password" :value="t('auth.new_password')" />
                <TextInput id="password" v-model="form.password" type="password" class="mt-1 block w-full" required />
                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div>
                <InputLabel for="password_confirmation" :value="t('auth.confirm_password')" />
                <TextInput
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    class="mt-1 block w-full"
                    required
                />
            </div>

            <PrimaryButton :disabled="form.processing">{{ t('config.save') }}</PrimaryButton>
        </form>
    </GuestLayout>
</template>
