<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
const props = defineProps<{ registering: boolean }>();
const form = useForm({ name: '', email: '', password: '', password_confirmation: '' });
function submit() {
    form.post(props.registering ? '/register' : '/login', { onFinish: () => form.reset('password', 'password_confirmation') });
}
</script>
<template>
    <Head :title="registering ? 'Create account' : 'Sign in'" />
    <main class="account shell">
        <header>Pixelforge</header>
        <h1>{{ registering ? 'Create account' : 'Sign in' }}</h1>
        <form @submit.prevent="submit">
            <label v-if="registering">Name<input v-model="form.name" autocomplete="name" required /></label>
            <label>Email<input v-model="form.email" type="email" autocomplete="email" required /></label>
            <label
                >Password<input v-model="form.password" type="password" :autocomplete="registering ? 'new-password' : 'current-password'" required
            /></label>
            <label v-if="registering"
                >Confirm password<input v-model="form.password_confirmation" type="password" autocomplete="new-password" required
            /></label>
            <p v-for="message in form.errors" :key="message" role="alert">{{ message }}</p>
            <button class="primary" :disabled="form.processing">{{ registering ? 'Create account' : 'Sign in' }}</button>
        </form>
        <Link :href="registering ? '/login' : '/register'">{{ registering ? 'Sign in to an existing account' : 'Create an account' }}</Link>
    </main>
</template>
