<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import Modal from '@/Components/Modal.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Search, ChevronDown, ChevronRight, AlertTriangle, ArrowLeftRight, SlidersHorizontal, ClipboardCheck, UserCheck } from 'lucide-vue-next';
import { displayDate } from '@/lib/dates';
import { usePermissions } from '@/composables/usePermissions';

const { t } = useI18n();
const { hasPermission: can } = usePermissions();

const props = defineProps({
    enabled: Boolean,
    variants: Object,
    warehouses: Array,
    owners: Array,
    filters: Object,
    issues: Array,
    transfers: Array,
});

const search = ref(props.filters?.search || '');
const warehouseFilter = ref(props.filters?.warehouse_id || '');
const ownerFilter = ref(props.filters?.owner || '');
const onlyAnomalies = ref(!!props.filters?.anomalies);
const onlyToCheck = ref(!!props.filters?.quality_control);

const applyFilters = () => {
    router.get(route('stock-by-warehouse.index'), {
        search: search.value || undefined,
        warehouse_id: warehouseFilter.value || undefined,
        owner: ownerFilter.value || undefined,
        anomalies: onlyAnomalies.value ? 1 : undefined,
        quality_control: onlyToCheck.value ? 1 : undefined,
    }, { preserveState: true, preserveScroll: true });
};

const ownerName = (id) => (id === null || id === undefined ? t('stockByWarehouse.unassigned') : (props.owners.find((o) => o.id === id)?.name ?? `#${id}`));
const warehouseName = (id) => props.warehouses.find((w) => w.id === id)?.name ?? `#${id}`;
const optionsLabel = (row) => Object.values(row.options || {}).join(' / ') || row.title;

const syncVariant = (status) => ({ synced: 'success', pending: 'warning', paused: 'neutral', error: 'danger', conflict: 'danger', unmapped: 'neutral' }[status] || 'neutral');
const channelVariant = (status) => ({ synced: 'success', pending: 'warning', error: 'danger', conflict: 'danger' }[status] || 'neutral');
const num = (n) => (n < 0 ? `−${Math.abs(n)}` : `${n}`);

// ------------------------------------------------------------------
// Row details
// ------------------------------------------------------------------

const expanded = ref(null);
const detail = ref(null);
const loadingDetail = ref(false);

const toggle = async (row) => {
    if (expanded.value === row.id) {
        expanded.value = null;
        return;
    }
    expanded.value = row.id;
    detail.value = null;
    loadingDetail.value = true;
    try {
        const response = await window.axios.get(route('stock-by-warehouse.show', row.id));
        detail.value = response.data;
    } finally {
        loadingDetail.value = false;
    }
};

// ------------------------------------------------------------------
// Actions
// ------------------------------------------------------------------

const modal = ref(null); // 'adjust' | 'inspect' | 'state' | 'transfer' | 'owners'
const current = ref(null);
const form = reactive({});
const busy = ref(false);

const open = (kind, row = null, preset = {}) => {
    current.value = row;
    Object.keys(form).forEach((k) => delete form[k]);
    const firstWarehouse = props.warehouses[0]?.id ?? null;
    Object.assign(form, {
        warehouse_id: firstWarehouse,
        owner_id: '',
        mode: 'delta',
        quantity: 1,
        reason: '',
        outcome: 'available',
        from: 'available',
        to: 'damaged',
        from_warehouse_id: props.warehouses[1]?.id ?? firstWarehouse,
        to_warehouse_id: firstWarehouse,
        notes: '',
        lines: [],
    }, preset);
    modal.value = kind;
};

const onHandAt = (row, warehouseId, ownerId) => {
    const wanted = ownerId === '' || ownerId === null || ownerId === undefined ? null : Number(ownerId);
    const match = (row?.warehouses?.[warehouseId]?.owners || []).find((o) => o.owner_id === wanted);
    return match ? match.on_hand : 0;
};

const ownerValue = (v) => (v === '' || v === 'unassigned' ? null : v);

const submit = () => {
    busy.value = true;
    const done = { preserveScroll: true, onFinish: () => (busy.value = false), onSuccess: () => { modal.value = null; if (expanded.value) { const id = expanded.value; expanded.value = null; toggle({ id }); } } };

    if (modal.value === 'adjust') {
        router.post(route('stock-by-warehouse.adjust', current.value.id), {
            warehouse_id: form.warehouse_id,
            owner_id: ownerValue(form.owner_id),
            mode: form.mode,
            quantity: Number(form.quantity),
            expected_on_hand: form.mode === 'count' ? onHandAt(current.value, form.warehouse_id, form.owner_id) : null,
            reason: form.reason,
        }, done);
    } else if (modal.value === 'inspect') {
        router.post(route('stock-by-warehouse.inspect', current.value.id), {
            warehouse_id: form.warehouse_id, owner_id: ownerValue(form.owner_id), quantity: Number(form.quantity), outcome: form.outcome, reason: form.reason,
        }, done);
    } else if (modal.value === 'state') {
        router.post(route('stock-by-warehouse.state', current.value.id), {
            warehouse_id: form.warehouse_id, owner_id: ownerValue(form.owner_id), quantity: Number(form.quantity), from: form.from, to: form.to, reason: form.reason,
        }, done);
    } else if (modal.value === 'transfer') {
        router.post(route('stock-by-warehouse.transfer'), {
            from_warehouse_id: form.from_warehouse_id, to_warehouse_id: form.to_warehouse_id, notes: form.notes,
            lines: form.lines.map((l) => ({ product_variant_id: l.product_variant_id, owner_id: ownerValue(l.owner_id), quantity: Number(l.quantity) })),
        }, done);
    } else if (modal.value === 'owners') {
        router.post(route('stock-by-warehouse.owners'), {
            lines: form.lines.filter((l) => Number(l.quantity) > 0).map((l) => ({
                product_variant_id: l.product_variant_id, warehouse_id: l.warehouse_id,
                from_owner_id: ownerValue(l.from_owner_id), to_owner_id: ownerValue(l.to_owner_id), quantity: Number(l.quantity),
            })),
        }, done);
    }
};

// Selection for transfers and owner assignment of several variants.
const selected = ref([]);
const rows = computed(() => props.variants?.data || []);
const selectedRows = computed(() => rows.value.filter((r) => selected.value.includes(r.id)));

const openTransfer = (row = null) => {
    const list = row ? [row] : selectedRows.value;
    open('transfer', null, {
        lines: list.map((r) => ({ product_variant_id: r.id, label: `${r.model}, ${optionsLabel(r)} (${r.sku})`, owner_id: '', quantity: 1 })),
    });
};

const openOwners = (row = null) => {
    const list = row ? [row] : selectedRows.value;
    const lines = [];
    list.forEach((r) => {
        props.warehouses.forEach((w) => {
            const unassigned = (r.warehouses[w.id]?.owners || []).find((o) => o.owner_id === null);
            if (unassigned && unassigned.available > 0) {
                lines.push({
                    product_variant_id: r.id, warehouse_id: w.id, label: `${r.model}, ${optionsLabel(r)} (${r.sku}) · ${w.name}`,
                    from_owner_id: '', to_owner_id: props.owners[0]?.id ?? '', quantity: unassigned.available, max: unassigned.available,
                });
            }
        });
    });
    open('owners', null, { lines });
};

const runIssueAction = (action) => router.post(action.url, {}, { preserveScroll: true });

const inputClass = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary ds-focus-ring';
const thClass = 'px-3 py-2.5 text-left text-xs font-medium text-text-secondary whitespace-nowrap';
const tdClass = 'px-3 py-2 text-sm text-text-primary align-top';
</script>

<template>
    <Head :title="t('stockByWarehouse.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">{{ t('nav.sections.stock') }}</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('nav.stockByWarehouse') }}</span>
            </div>
        </template>

        <PageHeader :title="t('stockByWarehouse.title')" :description="t('stockByWarehouse.subtitle')">
            <template #actions>
                <Button v-if="can('transfer_stock')" variant="secondary" size="sm" :disabled="!selectedRows.length" @click="openTransfer()">
                    <ArrowLeftRight :size="14" /> {{ t('stockByWarehouse.actions.transfer') }}
                </Button>
                <Button v-if="can('manage_stock')" variant="secondary" size="sm" :disabled="!selectedRows.length" @click="openOwners()">
                    <UserCheck :size="14" /> {{ t('stockByWarehouse.actions.assignSelected') }}
                </Button>
            </template>
        </PageHeader>

        <Card v-if="!enabled" class="mb-4 p-4 text-sm text-text-secondary">{{ t('stockByWarehouse.disabled') }}</Card>

        <!-- Sync anomalies -->
        <Card v-if="issues?.length" class="mb-4 p-4">
            <div class="mb-2 flex items-center gap-2 text-sm font-medium text-text-primary">
                <AlertTriangle :size="16" class="text-status-warning" /> {{ t('stockByWarehouse.issues.title') }} ({{ issues.length }})
            </div>
            <ul class="divide-y divide-border-subtle">
                <li v-for="issue in issues" :key="issue.id" class="flex flex-wrap items-start justify-between gap-2 py-2 text-sm">
                    <div>
                        <Badge variant="danger" size="sm">{{ issue.channel }}</Badge>
                        <span class="ml-2 text-text-primary">{{ issue.message }}</span>
                        <div class="text-xs text-text-tertiary">{{ displayDate(issue.created_at) }}<span v-if="issue.warehouse_id"> · {{ warehouseName(issue.warehouse_id) }}</span></div>
                    </div>
                    <div class="flex flex-wrap gap-1">
                        <Button v-for="action in issue.actions" :key="action.url" size="xs" variant="outline" @click="runIssueAction(action)">{{ action.label }}</Button>
                    </div>
                </li>
            </ul>
        </Card>

        <!-- Filters -->
        <Card class="mb-4 p-4">
            <div class="grid grid-cols-1 gap-3 md:grid-cols-6">
                <div class="relative md:col-span-2">
                    <Search :size="14" class="absolute left-3 top-1/2 -translate-y-1/2 text-text-tertiary" />
                    <input v-model="search" type="text" :placeholder="t('stockByWarehouse.search')" :class="inputClass" class="pl-8" @keyup.enter="applyFilters" />
                </div>
                <select v-model="warehouseFilter" :class="inputClass" @change="applyFilters">
                    <option value="">{{ t('stockByWarehouse.allWarehouses') }}</option>
                    <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
                </select>
                <select v-model="ownerFilter" :class="inputClass" @change="applyFilters">
                    <option value="">{{ t('stockByWarehouse.allOwners') }}</option>
                    <option v-for="o in owners" :key="o.id" :value="String(o.id)">{{ o.name }}</option>
                    <option value="unassigned">{{ t('stockByWarehouse.unassigned') }}</option>
                </select>
                <label class="flex items-center gap-2 text-sm text-text-secondary"><input v-model="onlyAnomalies" type="checkbox" @change="applyFilters" /> {{ t('stockByWarehouse.onlyAnomalies') }}</label>
                <label class="flex items-center gap-2 text-sm text-text-secondary"><input v-model="onlyToCheck" type="checkbox" @change="applyFilters" /> {{ t('stockByWarehouse.onlyToCheck') }}</label>
            </div>
        </Card>

        <!-- The grid -->
        <Card class="overflow-x-auto">
            <table class="min-w-full divide-y divide-border-subtle">
                <thead class="bg-surface-overlay">
                    <tr>
                        <th :class="thClass" class="w-8"></th>
                        <th :class="thClass">{{ t('stockByWarehouse.model') }}</th>
                        <th :class="thClass">{{ t('stockByWarehouse.variant') }}</th>
                        <th :class="thClass">{{ t('stockByWarehouse.sku') }}</th>
                        <th :class="thClass">{{ t('stockByWarehouse.barcode') }}</th>
                        <th v-for="w in warehouses" :key="w.id" :class="thClass" class="text-right">{{ w.name }}</th>
                        <th :class="thClass" class="text-right">{{ t('stockByWarehouse.total') }}</th>
                        <th :class="thClass">{{ t('stockByWarehouse.anomalies') }}</th>
                        <th :class="thClass"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border-subtle">
                    <template v-for="row in rows" :key="row.id">
                        <tr class="hover:bg-surface-overlay/40">
                            <td :class="tdClass"><input v-model="selected" type="checkbox" :value="row.id" /></td>
                            <td :class="tdClass">
                                <button type="button" class="inline-flex items-center gap-1 text-left font-medium hover:text-brand" @click="toggle(row)">
                                    <ChevronDown v-if="expanded === row.id" :size="14" /><ChevronRight v-else :size="14" />
                                    {{ row.model }}
                                </button>
                            </td>
                            <td :class="tdClass">{{ optionsLabel(row) }}</td>
                            <td :class="tdClass" class="font-mono text-xs">{{ row.sku }}</td>
                            <td :class="tdClass" class="font-mono text-xs">{{ row.barcode || '-' }}</td>
                            <td v-for="w in warehouses" :key="w.id" :class="tdClass" class="text-right">
                                <div class="font-semibold" :class="(row.warehouses[w.id]?.available ?? 0) < 0 ? 'text-status-danger' : ''">
                                    {{ num(row.warehouses[w.id]?.available ?? 0) }}
                                    <span class="text-xs font-normal text-text-tertiary">/ {{ num(row.warehouses[w.id]?.on_hand ?? 0) }}</span>
                                </div>
                                <div class="text-[11px] text-text-tertiary">
                                    <span v-if="row.warehouses[w.id]?.committed">{{ t('stockByWarehouse.committed') }} {{ row.warehouses[w.id].committed }} · </span>
                                    <span v-if="row.warehouses[w.id]?.quality_control">{{ t('stockByWarehouse.qualityControl') }} {{ row.warehouses[w.id].quality_control }} · </span>
                                    <span v-if="(row.warehouses[w.id]?.damaged || 0) + (row.warehouses[w.id]?.reserved || 0)">{{ t('stockByWarehouse.unavailable') }} {{ row.warehouses[w.id].damaged + row.warehouses[w.id].reserved }} · </span>
                                    <span v-if="row.warehouses[w.id]?.incoming">{{ t('stockByWarehouse.incoming') }} {{ row.warehouses[w.id].incoming }}</span>
                                </div>
                                <Badge v-if="row.warehouses[w.id]?.channel" size="sm" :variant="syncVariant(row.warehouses[w.id].channel.status)"
                                    :title="(row.warehouses[w.id].channel.detail || '') + ' · ' + t('stockByWarehouse.sync.lastSynced', { at: row.warehouses[w.id].channel.last_synced_at ? displayDate(row.warehouses[w.id].channel.last_synced_at) : t('stockByWarehouse.sync.never') })">
                                    {{ row.warehouses[w.id].channel.channel }}: {{ t('stockByWarehouse.sync.' + row.warehouses[w.id].channel.status) }}
                                </Badge>
                            </td>
                            <td :class="tdClass" class="text-right">
                                <div class="font-semibold">{{ num(row.total.available) }} <span class="text-xs font-normal text-text-tertiary">/ {{ num(row.total.on_hand) }}</span></div>
                                <div v-if="row.unassigned" class="text-[11px] text-status-warning">{{ t('stockByWarehouse.unassigned') }}: {{ row.unassigned }}</div>
                            </td>
                            <td :class="tdClass">
                                <div class="flex flex-wrap gap-1">
                                    <Badge v-for="(a, i) in row.anomalies" :key="i" size="sm" variant="danger">
                                        {{ t('stockByWarehouse.anomaly.' + a.kind) }}<span v-if="a.warehouse_id"> · {{ warehouseName(a.warehouse_id) }}</span>
                                    </Badge>
                                </div>
                            </td>
                            <td :class="tdClass" class="whitespace-nowrap text-right">
                                <Button v-if="can('manage_stock')" size="xs" variant="ghost" :title="t('stockByWarehouse.actions.adjust')" @click="open('adjust', row)"><SlidersHorizontal :size="14" /></Button>
                                <Button v-if="can('transfer_stock')" size="xs" variant="ghost" :title="t('stockByWarehouse.actions.transfer')" @click="openTransfer(row)"><ArrowLeftRight :size="14" /></Button>
                                <Button v-if="can('manage_stock')" size="xs" variant="ghost" :title="t('stockByWarehouse.actions.inspect')" @click="open('inspect', row)"><ClipboardCheck :size="14" /></Button>
                                <Button v-if="can('manage_stock')" size="xs" variant="ghost" :title="t('stockByWarehouse.actions.assignOwner')" @click="openOwners(row)"><UserCheck :size="14" /></Button>
                            </td>
                        </tr>

                        <tr v-if="expanded === row.id">
                            <td :colspan="7 + warehouses.length" class="bg-surface-sunken px-6 py-4">
                                <div v-if="loadingDetail" class="text-sm text-text-tertiary">…</div>
                                <div v-else-if="detail" class="grid gap-6 lg:grid-cols-3">
                                    <div>
                                        <div class="mb-2 text-xs font-semibold uppercase text-text-tertiary">{{ t('stockByWarehouse.owners') }}</div>
                                        <table class="w-full text-xs">
                                            <thead><tr class="text-text-tertiary">
                                                <th class="text-left">{{ t('stockByWarehouse.form.warehouse') }}</th><th class="text-left">{{ t('stockByWarehouse.owner') }}</th>
                                                <th class="text-right">{{ t('stockByWarehouse.onHand') }}</th><th class="text-right">{{ t('stockByWarehouse.available') }}</th>
                                                <th class="text-right">{{ t('stockByWarehouse.committed') }}</th><th class="text-right">{{ t('stockByWarehouse.qualityControl') }}</th>
                                                <th class="text-right">{{ t('stockByWarehouse.damaged') }}</th><th class="text-right">{{ t('stockByWarehouse.reserved') }}</th>
                                                <th class="text-right">{{ t('stockByWarehouse.incoming') }}</th>
                                            </tr></thead>
                                            <tbody>
                                                <tr v-for="l in detail.levels" :key="l.id">
                                                    <td>{{ l.warehouse?.name }}</td><td>{{ l.owner?.name || t('stockByWarehouse.unassigned') }}</td>
                                                    <td class="text-right" :class="l.on_hand < 0 ? 'text-status-danger' : ''">{{ num(l.on_hand) }}</td>
                                                    <td class="text-right" :class="l.available < 0 ? 'text-status-danger' : ''">{{ num(l.available) }}</td>
                                                    <td class="text-right">{{ l.committed }}</td><td class="text-right">{{ l.quality_control }}</td>
                                                    <td class="text-right">{{ l.damaged }}</td><td class="text-right">{{ l.reserved }}</td><td class="text-right">{{ l.incoming }}</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            <Button v-if="can('manage_stock')" size="xs" variant="outline" @click="open('state', row)">{{ t('stockByWarehouse.actions.state') }}</Button>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="mb-2 text-xs font-semibold uppercase text-text-tertiary">{{ t('stockByWarehouse.openCommitments') }}</div>
                                        <div v-if="!detail.allocations.length" class="text-xs text-text-tertiary">{{ t('stockByWarehouse.noCommitments') }}</div>
                                        <ul class="space-y-1 text-xs">
                                            <li v-for="(a, i) in detail.allocations" :key="i">
                                                <Link :href="route('orders.show', a.order_id)" class="text-brand hover:underline">{{ a.order?.external_reference || a.order?.order_number }}</Link>
                                                · {{ a.warehouse }} · {{ a.owner || t('stockByWarehouse.unassigned') }} · {{ a.open }}
                                                <Badge v-if="a.shortfall" size="sm" variant="danger">{{ a.shortfall }} {{ t('stockByWarehouse.shortfall') }}</Badge>
                                            </li>
                                        </ul>
                                    </div>
                                    <div>
                                        <div class="mb-2 text-xs font-semibold uppercase text-text-tertiary">{{ t('stockByWarehouse.history') }}</div>
                                        <div v-if="!detail.history.length" class="text-xs text-text-tertiary">{{ t('stockByWarehouse.noHistory') }}</div>
                                        <ul class="max-h-64 space-y-1 overflow-y-auto text-xs">
                                            <li v-for="h in detail.history" :key="h.id">
                                                <span class="text-text-tertiary">{{ displayDate(h.at) }}</span>
                                                · {{ h.warehouse }} · {{ h.bucket }} <strong>{{ h.delta > 0 ? '+' : '' }}{{ h.delta }}</strong> ({{ h.before }} → {{ h.after }})
                                                · {{ h.owner || t('stockByWarehouse.unassigned') }} · {{ h.type }}<span v-if="h.source !== 'local'"> [{{ h.source }}]</span>
                                                <span v-if="h.reason">: {{ h.reason }}</span><span v-if="h.user"> ({{ h.user }})</span>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="!rows.length">
                        <td :colspan="7 + warehouses.length" class="px-4 py-8 text-center text-sm text-text-tertiary">{{ t('stockByWarehouse.noVariants') }}</td>
                    </tr>
                </tbody>
            </table>
        </Card>

        <div v-if="variants?.links?.length > 3" class="mt-4 flex flex-wrap gap-1">
            <Link v-for="(link, i) in variants.links" :key="i" :href="link.url || '#'" preserve-scroll
                class="rounded-md border border-border-subtle px-3 py-1 text-xs"
                :class="[link.active ? 'bg-brand text-brand-foreground' : 'text-text-secondary', !link.url ? 'pointer-events-none opacity-50' : '']"
                v-html="link.label" />
        </div>

        <!-- Recent transfers -->
        <Card class="mt-6 p-4">
            <div class="mb-2 text-sm font-medium text-text-primary">{{ t('stockByWarehouse.transfers.title') }}</div>
            <div v-if="!transfers.length" class="text-sm text-text-tertiary">{{ t('stockByWarehouse.transfers.none') }}</div>
            <table v-else class="w-full text-sm">
                <thead><tr>
                    <th :class="thClass">{{ t('stockByWarehouse.transfers.reference') }}</th><th :class="thClass">{{ t('stockByWarehouse.transfers.route') }}</th>
                    <th :class="thClass">{{ t('stockByWarehouse.transfers.pairs') }}</th><th :class="thClass">{{ t('stockByWarehouse.transfers.order') }}</th>
                    <th :class="thClass">{{ t('stockByWarehouse.transfers.channel') }}</th><th :class="thClass"></th>
                </tr></thead>
                <tbody class="divide-y divide-border-subtle">
                    <tr v-for="tr in transfers" :key="tr.id">
                        <td :class="tdClass" class="font-mono text-xs">{{ tr.reference }}</td>
                        <td :class="tdClass">{{ tr.from_warehouse?.name }} → {{ tr.to_warehouse?.name }}</td>
                        <td :class="tdClass">{{ tr.lines.map((l) => `${l.variant?.sku} × ${l.quantity}`).join(', ') }}</td>
                        <td :class="tdClass">{{ tr.order?.external_reference || tr.order?.order_number || '-' }}</td>
                        <td :class="tdClass">
                            <Badge size="sm" :variant="channelVariant(tr.channel_status)" :title="tr.channel_error || ''">{{ t('stockByWarehouse.transfers.channelStatus.' + tr.channel_status) }}</Badge>
                        </td>
                        <td :class="tdClass" class="text-xs text-text-tertiary">{{ displayDate(tr.completed_at) }} · {{ tr.user?.name }}</td>
                    </tr>
                </tbody>
            </table>
        </Card>

        <!-- Action dialogs -->
        <Modal :show="modal !== null" max-width="2xl" @close="modal = null">
            <form class="space-y-4 p-6" @submit.prevent="submit">
                <h2 class="text-base font-semibold text-text-primary">
                    {{ t('stockByWarehouse.actions.' + ({ adjust: 'adjust', inspect: 'inspect', state: 'state', transfer: 'transfer', owners: 'assignOwner' }[modal] || 'adjust')) }}
                    <span v-if="current" class="text-sm font-normal text-text-secondary">: {{ current.model }} {{ optionsLabel(current) }} ({{ current.sku }})</span>
                </h2>

                <template v-if="['adjust', 'inspect', 'state'].includes(modal)">
                    <div class="grid grid-cols-2 gap-3">
                        <label class="text-sm">{{ t('stockByWarehouse.form.warehouse') }}
                            <select v-model="form.warehouse_id" :class="inputClass"><option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option></select>
                        </label>
                        <label class="text-sm">{{ t('stockByWarehouse.owner') }}
                            <select v-model="form.owner_id" :class="inputClass">
                                <option value="">{{ t('stockByWarehouse.unassigned') }}</option>
                                <option v-for="o in owners" :key="o.id" :value="o.id">{{ o.name }}</option>
                            </select>
                        </label>
                    </div>
                </template>

                <template v-if="modal === 'adjust'">
                    <label class="block text-sm">{{ t('stockByWarehouse.form.mode') }}
                        <select v-model="form.mode" :class="inputClass">
                            <option value="delta">{{ t('stockByWarehouse.form.delta') }}</option>
                            <option value="count">{{ t('stockByWarehouse.form.count') }}</option>
                        </select>
                    </label>
                    <p v-if="form.mode === 'count'" class="text-xs text-text-tertiary">{{ t('stockByWarehouse.form.countHint', { n: onHandAt(current, form.warehouse_id, form.owner_id) }) }}</p>
                    <label class="block text-sm">{{ t('stockByWarehouse.form.quantity') }}<input v-model="form.quantity" type="number" :class="inputClass" required /></label>
                    <label class="block text-sm">{{ t('stockByWarehouse.form.reason') }}<input v-model="form.reason" type="text" :class="inputClass" required maxlength="255" /></label>
                </template>

                <template v-if="modal === 'inspect'">
                    <label class="block text-sm">{{ t('stockByWarehouse.form.quantity') }}<input v-model="form.quantity" type="number" min="1" :class="inputClass" required /></label>
                    <label class="block text-sm">{{ t('stockByWarehouse.form.outcome') }}
                        <select v-model="form.outcome" :class="inputClass">
                            <option value="available">{{ t('stockByWarehouse.form.outcomeAvailable') }}</option>
                            <option value="damaged">{{ t('stockByWarehouse.form.outcomeDamaged') }}</option>
                            <option value="reserved">{{ t('stockByWarehouse.form.outcomeReserved') }}</option>
                        </select>
                    </label>
                    <label class="block text-sm">{{ t('stockByWarehouse.form.reason') }}<input v-model="form.reason" type="text" :class="inputClass" :required="form.outcome !== 'available'" maxlength="255" /></label>
                </template>

                <template v-if="modal === 'state'">
                    <div class="grid grid-cols-2 gap-3">
                        <label class="text-sm">{{ t('stockByWarehouse.form.fromState') }}
                            <select v-model="form.from" :class="inputClass">
                                <option v-for="s in ['available', 'quality_control', 'damaged', 'reserved']" :key="s" :value="s">{{ t('stockByWarehouse.' + { available: 'available', quality_control: 'qualityControl', damaged: 'damaged', reserved: 'reserved' }[s]) }}</option>
                            </select>
                        </label>
                        <label class="text-sm">{{ t('stockByWarehouse.form.toState') }}
                            <select v-model="form.to" :class="inputClass">
                                <option v-for="s in ['available', 'quality_control', 'damaged', 'reserved']" :key="s" :value="s">{{ t('stockByWarehouse.' + { available: 'available', quality_control: 'qualityControl', damaged: 'damaged', reserved: 'reserved' }[s]) }}</option>
                            </select>
                        </label>
                    </div>
                    <label class="block text-sm">{{ t('stockByWarehouse.form.quantity') }}<input v-model="form.quantity" type="number" min="1" :class="inputClass" required /></label>
                    <label class="block text-sm">{{ t('stockByWarehouse.form.reason') }}<input v-model="form.reason" type="text" :class="inputClass" required maxlength="255" /></label>
                </template>

                <template v-if="modal === 'transfer'">
                    <p class="text-xs text-text-tertiary">{{ t('stockByWarehouse.form.transferHint') }}</p>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="text-sm">{{ t('stockByWarehouse.form.from') }}
                            <select v-model="form.from_warehouse_id" :class="inputClass"><option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option></select>
                        </label>
                        <label class="text-sm">{{ t('stockByWarehouse.form.to') }}
                            <select v-model="form.to_warehouse_id" :class="inputClass"><option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option></select>
                        </label>
                    </div>
                    <div class="space-y-2">
                        <div class="text-xs font-semibold uppercase text-text-tertiary">{{ t('stockByWarehouse.form.lines') }}</div>
                        <div v-for="(line, i) in form.lines" :key="i" class="grid grid-cols-6 items-center gap-2 text-sm">
                            <div class="col-span-3">{{ line.label }}</div>
                            <select v-model="line.owner_id" :class="inputClass" class="col-span-2">
                                <option value="">{{ t('stockByWarehouse.unassigned') }}</option>
                                <option v-for="o in owners" :key="o.id" :value="o.id">{{ o.name }}</option>
                            </select>
                            <input v-model="line.quantity" type="number" min="1" :class="inputClass" />
                        </div>
                    </div>
                    <label class="block text-sm">{{ t('stockByWarehouse.form.notes') }}<input v-model="form.notes" type="text" :class="inputClass" maxlength="1000" /></label>
                </template>

                <template v-if="modal === 'owners'">
                    <p class="text-xs text-text-tertiary">{{ t('stockByWarehouse.form.ownerHint') }}</p>
                    <div v-for="(line, i) in form.lines" :key="i" class="grid grid-cols-6 items-center gap-2 text-sm">
                        <div class="col-span-3">{{ line.label }}</div>
                        <select v-model="line.to_owner_id" :class="inputClass" class="col-span-2">
                            <option v-for="o in owners" :key="o.id" :value="o.id">{{ o.name }}</option>
                        </select>
                        <input v-model="line.quantity" type="number" min="0" :max="line.max" :class="inputClass" />
                    </div>
                    <p v-if="!form.lines.length" class="text-sm text-text-tertiary">{{ t('stockByWarehouse.noVariants') }}</p>
                </template>

                <div class="flex justify-end gap-2 pt-2">
                    <Button type="button" variant="secondary" size="sm" @click="modal = null">{{ t('stockByWarehouse.actions.cancel') }}</Button>
                    <Button type="submit" size="sm" :disabled="busy">{{ t('stockByWarehouse.actions.confirm') }}</Button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
