<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { ChevronDown, UserRound } from 'lucide-vue-next';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';

type Quote = { wallet_enabled: boolean; min: string; max: string; quote_token: string };
type Generation = {
    id: string;
    prompt: string;
    status: string;
    message: string | null;
    image_url: string | null;
    image_expires_at: string | null;
    minimum: string;
    maximum: string;
    customer_charge: string | null;
};
type Input = { prompt: string; size: string; quality: string; reference_images: string[] };
const props = defineProps<{
    userId: number;
    active: boolean;
    walletsEnabled: boolean;
    history: { data: Generation[]; next_page_url: string | null; prev_page_url: string | null };
}>();
const storageKey = 'pixelforge.submission.' + props.userId;
const empty: Input = { prompt: '', size: '1024x1024', quality: 'medium', reference_images: [] };
function restore(): { input: Input; key: string } | null {
    try {
        return JSON.parse(sessionStorage.getItem(storageKey) || 'null');
    } catch {
        return null;
    }
}
const saved = restore();
const input = reactive<Input>(saved?.input ?? empty);
const key = ref(saved?.key ?? crypto.randomUUID());
const payload = computed(() => ({ ...input, reference_images: input.reference_images.map((url) => url.trim()).filter(Boolean) }));
const quote = ref<Quote | null>(null);
const showWallet = computed(() => quote.value?.wallet_enabled ?? props.walletsEnabled);
const busy = ref(false);
const loading = ref(false);
const message = ref('');
const invalid = ref<Record<string, string[]>>({});
const missingImages = reactive(new Set<string>());
const tick = ref(Date.now());
const retry = ref(0);
function money(value: string) {
    return '$' + value.replace(/(\.\d*?[1-9])0+$|\.0+$/, '$1');
}
function expired(row: Generation) {
    return !!row.image_expires_at && Date.parse(row.image_expires_at) <= tick.value;
}
function updateError(error: unknown, fallback: string) {
    if (axios.isAxiosError(error)) {
        invalid.value = error.response?.data?.errors || {};
        message.value = error.response?.data?.message || fallback;
    } else message.value = fallback;
}
watch(
    [() => JSON.stringify(payload.value), retry],
    ([fingerprint], [previous] = ['', 0], onCleanup) => {
        quote.value = null;
        message.value = '';
        invalid.value = {};
        loading.value = false;
        if (previous && previous !== fingerprint) key.value = crypto.randomUUID();
        if (!input.prompt.trim()) return;
        loading.value = true;
        const controller = new AbortController();
        const timer = setTimeout(async () => {
            try {
                const res = await axios.post('/quotes', payload.value, { signal: controller.signal });
                if (!controller.signal.aborted) quote.value = res.data;
            } catch (e) {
                if (!controller.signal.aborted) updateError(e, 'Could not load the estimate. Try again.');
            } finally {
                if (!controller.signal.aborted) loading.value = false;
            }
        }, 250);
        onCleanup(() => {
            clearTimeout(timer);
            controller.abort();
        });
    },
    { immediate: true },
);
async function generate() {
    if (!quote.value || busy.value) return;
    busy.value = true;
    message.value = '';
    invalid.value = {};
    try {
        sessionStorage.setItem(storageKey, JSON.stringify({ input: payload.value, key: key.value }));
        await axios.post('/generations', { ...payload.value, submission_key: key.value, quote_token: quote.value.quote_token });
        sessionStorage.removeItem(storageKey);
        key.value = crypto.randomUUID();
        router.visit('/dashboard', { preserveState: true, preserveScroll: true });
    } catch (e) {
        updateError(e, 'Could not confirm the submission. Retry to check the same request.');
        if (axios.isAxiosError(e) && e.response?.data?.quote) quote.value = e.response.data.quote;
    } finally {
        busy.value = false;
    }
}
let polling = false;
const timer = setInterval(() => {
    tick.value = Date.now();
    if (props.active && !polling && !busy.value && !document.hidden) {
        polling = true;
        router.reload({
            only: ['history', 'active'],
            onFinish: () => {
                polling = false;
            },
        });
    }
}, 3500);
onBeforeUnmount(() => clearInterval(timer));
</script>
<template>
    <Head title="New generation" />
    <main class="shell">
        <header>
            <span>Pixelforge</span>
            <Link v-if="showWallet" href="/wallet">Wallet</Link>
            <details class="account-menu">
                <summary aria-label="Account menu"><UserRound aria-hidden="true" /></summary>
                <Link as="button" method="post" href="/logout">Sign out</Link>
            </details>
        </header>
        <div class="studio">
            <section aria-labelledby="compose">
                <h1 id="compose">New generation</h1>
                <form @submit.prevent="generate">
                    <fieldset :disabled="busy">
                        <label class="prompt-field"
                            >Prompt<textarea v-model="input.prompt" rows="3" placeholder="Describe the image you want to create" required />
                        </label>
                        <p v-if="invalid.prompt" role="alert">{{ invalid.prompt[0] }}</p>
                        <label class="settings-row"
                            >Size<select v-model="input.size">
                                <option v-for="size in ['1024x1024', '1536x1024', '1024x1536']" :key="size" :value="size">
                                    {{ size.replace('x', ' × ') }}
                                </option>
                            </select></label
                        >
                        <div class="settings-row">
                            <span id="quality">Quality</span>
                            <div class="segments" role="group" aria-labelledby="quality">
                                <button
                                    v-for="q in ['low', 'medium', 'high']"
                                    :key="q"
                                    type="button"
                                    :aria-pressed="input.quality === q"
                                    @click="input.quality = q"
                                >
                                    {{ q }}
                                </button>
                            </div>
                        </div>
                        <details class="references" :open="input.reference_images.length > 0 || undefined">
                            <summary>Reference images <ChevronDown aria-hidden="true" /></summary>
                            <div v-for="(_, i) in input.reference_images" :key="i" class="reference">
                                <label>Reference URL {{ i + 1 }}<input v-model="input.reference_images[i]" type="url" placeholder="https://" /></label
                                ><button type="button" @click="input.reference_images.splice(i, 1)">Remove</button>
                                <p v-if="invalid['reference_images.' + i]" role="alert">{{ invalid['reference_images.' + i][0] }}</p>
                            </div>
                            <button
                                v-if="input.reference_images.length < 4"
                                type="button"
                                class="text-action"
                                @click="input.reference_images.push('')"
                            >
                                Add reference URL
                            </button>
                        </details>
                        <div class="quote-action">
                            <div aria-live="polite">
                                <span class="muted">Estimate</span>
                                <div class="amount">{{ quote ? money(quote.min) + '–' + money(quote.max) : loading ? 'Calculating…' : '—' }}</div>
                            </div>
                            <button class="primary" :disabled="!quote || busy">{{ busy ? 'Submitting…' : 'Generate' }}</button>
                        </div>
                        <p v-if="showWallet" class="muted wallet-hint">The hold is the upper estimate at current rates. Your final charge uses the rates Pixelforge applies when it accepts your request.</p>
                        <p v-if="message" role="alert">
                            {{ message }} <Link v-if="showWallet && message.startsWith('Insufficient')" href="/wallet">Buy test credits</Link> <button v-if="!quote" type="button" class="text-action" @click="retry++">Retry estimate</button>
                        </p>
                    </fieldset>
                </form>
            </section>
            <section aria-labelledby="activity" class="activity">
                <h2 id="activity">Recent activity</h2>
                <p class="muted" v-if="!history.data.length">Your images and their final charges will appear here.</p>
                <ol aria-live="polite" aria-relevant="additions text">
                    <li v-for="row in history.data" :key="row.id" :data-status="row.status">
                        <strong class="status">{{ row.status }}</strong>
                        <p class="entry-prompt">{{ row.prompt }}</p>
                        <template v-if="row.status === 'succeeded'"
                            ><img
                                v-if="row.image_url && !expired(row) && !missingImages.has(row.id)"
                                :src="row.image_url"
                                :alt="row.prompt"
                                @error="missingImages.add(row.id)"
                            />
                            <p v-else class="muted">{{ expired(row) ? 'Image link expired.' : 'Image unavailable.' }}</p></template
                        >
                        <p v-if="row.message">{{ row.message }}</p>
                        <dl>
                            <dt>Estimated</dt>
                            <dd>{{ money(row.minimum) }}–{{ money(row.maximum) }}</dd>
                            <dt>Charged</dt>
                            <dd>{{ row.customer_charge === null ? 'Pending' : money(row.customer_charge) }}</dd>
                        </dl>
                    </li>
                </ol>
                <nav aria-label="Activity pages">
                    <Link v-if="history.prev_page_url" :href="history.prev_page_url">Newer</Link
                    ><Link v-if="history.next_page_url" :href="history.next_page_url">Older</Link>
                </nav>
            </section>
        </div>
    </main>
</template>
