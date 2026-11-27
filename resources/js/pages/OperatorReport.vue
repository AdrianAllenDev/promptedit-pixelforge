<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
type Amounts = { customer_charge: string; provider_cost: string; profit: string };
type Compared = Amounts & { reported: string | null; difference: string | null };
type UserAmounts = Amounts & { id: number; name: string };
const props = defineProps<{
    range: { start: string; end: string };
    report: { daily: (Compared & { date: string; users: UserAmounts[] })[]; users: UserAmounts[]; total: Compared; checkedAt: string; reconciled: boolean };
}>();
const filter = useForm({ ...props.range });
const amounts = { customer_charge: 'Customer spend', provider_cost: 'Provider cost', profit: 'Profit' } as const;
const compared = { ...amounts, reported: 'Pixelforge usage', difference: 'Difference' } as const;
const usd = (value: string | null) => value === null ? 'Unavailable' : '$' + value.replace(/(\.\d*?[1-9])0+$|\.0+$/, '$1');
</script>
<template>
    <Head title="Operator report" />
    <main class="shell report">
        <header><span>Pixelforge administration</span><Link as="button" method="post" href="/admin/logout">Sign out</Link></header>
        <h1>Operator report</h1>
        <p><Link href="/admin/generations/review">Review unresolved generations</Link></p>
        <form class="report-filter" @submit.prevent="filter.get('/admin/report', { preserveState: true, preserveScroll: true })">
            <label>From (UTC)<input v-model="filter.start" type="date" required /><span role="alert" v-if="filter.errors.start">{{ filter.errors.start }}</span></label>
            <label>Through (UTC)<input v-model="filter.end" type="date" required /><span role="alert" v-if="filter.errors.end">{{ filter.errors.end }}</span></label>
            <button class="primary" :disabled="filter.processing">Run report</button>
        </form>
        <h2>Range totals</h2>
        <dl class="report-totals"><div v-for="(label, key) in compared" :key="key"><dt>{{ label }}</dt><dd>{{ usd(report.total[key]) }}</dd></div></dl>
        <p v-if="!report.reconciled" role="alert">Pixelforge usage is unavailable. Run the report again to compare costs.</p>
        <p class="muted">Checked {{ report.checkedAt }}. Difference is local provider cost minus Pixelforge usage.</p>
        <h2>By UTC completion day</h2>
        <table><thead><tr><th scope="col">Day</th><th v-for="label in compared" :key="label" scope="col">{{ label }}</th></tr></thead>
            <tbody v-for="row in report.daily" :key="row.date">
                <tr><th scope="row">{{ row.date }}</th><td v-for="(label,key) in compared" :key="key" :data-label="label">{{ usd(row[key]) }}</td></tr>
                <tr v-if="row.users.length"><td class="breakdown" colspan="6">
                    <details><summary>User breakdown for {{ row.date }}</summary>
                        <section v-for="user in row.users" :key="user.id">
                            <strong>{{ user.name }} (#{{ user.id }})</strong>
                            <dl class="report-totals"><div v-for="(label,key) in amounts" :key="key"><dt>{{ label }}</dt><dd>{{ usd(user[key]) }}</dd></div></dl>
                        </section>
                    </details>
                </td></tr>
            </tbody>
        </table>
        <h2>By user</h2>
        <p v-if="!report.users.length">No settled generations in this range.</p>
        <table v-else><thead><tr><th scope="col">User</th><th v-for="label in amounts" :key="label" scope="col">{{ label }}</th></tr></thead>
            <tbody><tr v-for="row in report.users" :key="row.id"><th scope="row">{{ row.name }} (#{{ row.id }})</th><td v-for="(label,key) in amounts" :key="key" :data-label="label">{{ usd(row[key]) }}</td></tr></tbody>
        </table>
    </main>
</template>
<style scoped>
header button {font-size:1rem}
.report-filter,.report-totals {display:flex; gap:1rem; flex-wrap:wrap; align-items:end}
.report-filter label {min-width:0}
.report-filter input {display:block}
.report-totals {margin-bottom:1rem}
.report-totals div {min-width:8rem}
table {width:100%;border-collapse:collapse;font-variant-numeric:tabular-nums}
th,td {padding:.65rem .45rem;text-align:left;border-bottom:1px solid #575753;overflow-wrap:anywhere}
th {color:#dedbd4}
.breakdown {display:table-cell;text-align:left}
.breakdown summary {padding:.65rem 0;cursor:pointer}
.breakdown section {padding:.5rem 0}
@media(max-width: 800px) {
    .report-filter label {flex:1 1 9rem}
    table,tbody,tr,td,tbody th {display:block}
    thead {position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%)}
    tr {margin-bottom:1rem;background:#242424;border-radius:.4rem;padding:.5rem}
    td {display:flex;justify-content:space-between;gap:.75rem;text-align:right}
    td::before {content:attr(data-label);text-align:left;color:#c7c5be}
    .breakdown {display:block}
    .breakdown::before {content:none}
}
</style>
