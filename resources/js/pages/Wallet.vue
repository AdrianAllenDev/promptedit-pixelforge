<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import { ref } from 'vue';

type Balance = { available: string; held: string; spent: string; due: string };
type Purchase = { purchase_key: string; amount: string };
const props = defineProps<{ balance: Balance; amounts: string[]; userId: number }>();
const storageKey = 'pixelforge.topup.' + props.userId;
function restorePurchase(): Purchase | null {
    try {
        return JSON.parse(sessionStorage.getItem(storageKey) || 'null');
    } catch { return null; }
}
const pending = ref<Purchase | null>(restorePurchase());
const selected = ref(pending.value?.amount ?? props.amounts[0]);
const balance = ref(props.balance);
const busy = ref(false);
const feedback = ref('');
const error = ref('');
const fields = { available: 'Available', held: 'Held for pending images', spent: 'Spent since wallet rollout', due: 'Amount due' } as const;
const money = (value:string) => '$' + value.replace(/(\.\d*?[1-9])0+$|\.0+$/, '$1');

async function buy() {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    feedback.value = '';
    try {
        const purchase = pending.value ?? { purchase_key: crypto.randomUUID(), amount: selected.value };
        pending.value = purchase;
        sessionStorage.setItem(storageKey, JSON.stringify(purchase));
        const response = await axios.post<Balance>('/wallet/top-ups', purchase);
        balance.value = response.data;
        sessionStorage.removeItem(storageKey);
        pending.value = null;
        feedback.value = '$' + purchase.amount + ' in test credits added.';
    } catch {
        error.value = 'Could not confirm this purchase. Retry to check the same top-up.';
    } finally { busy.value = false; }
}
</script>
<template>
    <Head title="Credit wallet" />
    <main class="shell wallet">
        <header><span>Pixelforge</span><Link href="/dashboard">Workspace</Link></header>
        <h1>Credit wallet</h1>
        <p class="muted">An unresolved image holds credit for up to 24 hours. An admin can release it earlier. If Pixelforge later confirms success, the charge uses available credit and any shortfall becomes an amount due.</p>
        <dl aria-live="polite"><div v-for="(label,key) in fields" :key="key"><dt>{{ label }}</dt><dd>{{ money(balance[key]) }}</dd></div></dl>
        <p v-if="!/^0(?:\.0+)?$/.test(balance.due)">Add credits to clear the amount due before generating another image.</p>
        <h2>Buy test credits</h2>
        <p class="muted">This is a simulated purchase. Credits pay any amount due first.</p>
        <form @submit.prevent="buy">
            <label>Top-up amount<select v-model="selected" :disabled="busy || !!pending"><option v-for="amount in amounts" :key="amount" :value="amount">${{ amount }}</option></select></label>
            <button class="primary" :disabled="busy">{{ busy ? 'Adding credits…' : pending ? 'Retry top-up' : 'Buy test credits' }}</button>
        </form>
        <p role="status">{{ feedback }}</p><p v-if="error" role="alert">{{ error }}</p>
    </main>
</template>
<style scoped>
.wallet {max-width:52rem}
header a {font-size:1rem}
dl {display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,12rem),1fr));gap:.8rem;margin:1rem 0}
dl div {padding:.85rem;border:1px solid #575753;border-radius:.5rem;background:#242424}
dd {font-size:1.35rem;margin-top:.2rem}
form {display:flex;flex-wrap:wrap;gap:1rem;align-items:end;margin:1rem 0}
label {min-width:9rem}
select {display:block;margin-top:.3rem}
</style>
