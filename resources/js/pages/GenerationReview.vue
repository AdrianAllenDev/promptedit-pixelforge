<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

type ReviewCase = {
    id: string;
    customer: string;
    provider_id: string | null;
    status: string;
    message: string | null;
    attempts: number;
    submit_attempts: number | null;
    poll_failures: number;
    created_at: string;
    recover_at: string | null;
    amount: string | null;
    deadline: string;
    hold_state: 'none' | 'held' | 'expired' | 'released';
    released_at: string | null;
    reviewer: string | null;
    release_reason: string | null;
};
const props = defineProps<{ cases: { data: ReviewCase[]; current_page: number; next_page_url: string | null; prev_page_url: string | null } }>();
const selected = ref<string | null>(null);
const feedback = ref('');
const release = useForm({ reason: '' });
const states = { none: 'No wallet hold', held: 'Reserved', expired: 'Released at the 24-hour deadline', released: 'Released by admin' };
const usd = (amount: string) => '$' + amount.replace(/(\.\d*?[1-9])0+$|\.0+$/, '$1');

function select(id: string | null) {
    selected.value = id;
    feedback.value = '';
    release.reset();
    release.clearErrors();
}
function releaseFunds(id: string) {
    release.post('/admin/generations/' + id + '/release?page=' + props.cases.current_page, {
        preserveScroll: true,
        onSuccess: () => {
            select(null);
            feedback.value = 'The hold is no longer reserving funds.';
        },
    });
}
</script>
<template>
    <Head title="Generation review" />
    <main class="shell review">
        <header><Link href="/admin/report">Operator report</Link><Link as="button" method="post" href="/admin/logout">Sign out</Link></header>
        <h1>Unresolved generations</h1>
        <p>Review these cases within 24 hours of submission. Unsettled holds expire at that deadline. Releasing a hold earlier returns the reserved credit. A later confirmed success can still create an amount due.</p>
        <p role="status">{{ feedback }}</p>
        <p v-if="!cases.data.length">No generations are waiting for review on this page.</p>
        <article v-for="item in cases.data" :key="item.id">
            <h2>{{ item.customer }}</h2>
            <dl>
                <dt>Generation</dt><dd>{{ item.id }}</dd>
                <dt>Pixelforge ID</dt><dd>{{ item.provider_id ?? 'Awaiting confirmation' }}</dd>
                <dt>Submitted (UTC)</dt><dd>{{ item.created_at }}</dd>
                <dt>Recorded status</dt><dd>{{ item.status }}</dd>
                <dt>Recovery</dt><dd>{{ item.recover_at ? 'Scheduled' : 'Paused' }} · {{ item.attempts }} {{ item.attempts === 1 ? 'attempt' : 'attempts' }}</dd>
                <dt>Provider calls</dt><dd>{{ item.submit_attempts ?? (item.provider_id ? 'Unknown' : item.attempts) }} submission attempts · {{ item.poll_failures }} consecutive failed polls</dd>
                <dt>Wallet hold</dt><dd>{{ states[item.hold_state] }}<template v-if="item.amount"> · {{ usd(item.amount) }}</template></dd>
                <dt>Review deadline (UTC)</dt><dd>{{ item.deadline }}</dd>
                <template v-if="item.released_at"><dt>Release decision</dt><dd>{{ item.reviewer }} · {{ item.released_at }} · {{ item.release_reason }}</dd></template>
            </dl>
            <p v-if="item.message">{{ item.message }}</p>
            <template v-if="item.hold_state === 'held'">
                <button v-if="selected !== item.id" type="button" class="text-action" :disabled="release.processing" @click="select(item.id)">Review hold</button>
                <form v-else @submit.prevent="releaseFunds(item.id)">
                    <label>Reason for release<textarea v-model="release.reason" required maxlength="500" rows="2" /></label>
                    <p v-if="release.errors.reason" role="alert">{{ release.errors.reason }}</p>
                    <button class="primary" :disabled="release.processing">Release {{ usd(item.amount!) }}</button>
                    <button type="button" :disabled="release.processing" @click="select(null)">Cancel</button>
                </form>
            </template>
        </article>
        <nav aria-label="Review pages">
            <Link v-if="cases.prev_page_url" :href="cases.prev_page_url">Previous</Link>
            <Link v-if="cases.next_page_url" :href="cases.next_page_url">Next</Link>
        </nav>
    </main>
</template>
<style scoped>
.review { max-width: 56rem; }
article { border-top: 1px solid #575753; margin: 1rem 0; overflow-wrap: anywhere; }
dl { display: grid; grid-template-columns: minmax(7rem, 1fr) minmax(0, 3fr); gap: .35rem 1rem; }
form { max-width: 36rem; }
form button { margin: .5rem .75rem .5rem 0; }
@media (max-width: 36rem) { dl { grid-template-columns: 1fr; gap: .1rem; } dd { margin-bottom: .5rem; } }
</style>
