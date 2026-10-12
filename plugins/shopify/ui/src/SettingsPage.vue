<script setup>
// The Shopify settings page (/p/shopify): connection, sync settings, order
// webhooks, full stock sync and the log of received Shopify orders.
import { computed, reactive, ref } from 'vue';
import { t } from './i18n.js';

const {
    layouts: { AppLayout },
    ui: { PageHeader, Card, CardHeader, Button, Badge },
    Inertia: { Head, Link, router, useForm },
} = window.Inventoros;

const props = defineProps({
    title: { type: String, default: 'Shopify' },
    connection: { type: Object, default: null },
    webhookUrl: { type: String, default: null },
    defaultApiVersion: { type: String, required: true },
    requiredScopes: { type: Array, default: () => [] },
    optionalScopes: { type: Array, default: () => [] },
    warehouses: { type: Array, default: () => [] },
    orders: { type: Array, default: () => [] },
});

const actions = '/p/shopify/actions';

const form = useForm({
    shop_domain: props.connection?.shop_domain ?? '',
    client_id: props.connection?.client_id ?? '',
    client_secret: '',
    api_version: props.connection?.api_version ?? props.defaultApiVersion,
    warehouse_id: props.connection?.warehouse_id ?? '',
    import_orders: props.connection?.import_orders ?? true,
    push_inventory: props.connection?.push_inventory ?? true,
});

const busy = ref(null);
const copied = ref(false);

// One select per Shopify location: '' = not synced, 'all' = total stock, or a warehouse id.
const mapping = reactive(Object.fromEntries((props.connection?.locations ?? []).map((location) => {
    const entry = (props.connection?.location_map ?? []).find((e) => e.location_id === location.id);
    return [location.id, entry ? (entry.warehouse_id ?? 'all') : ''];
})));

const recovery = useForm({ from: new Date(Date.now() - 7 * 86400000).toISOString().slice(0, 10) });
const catalogueImport = useForm({ with_stock: true });
const cataloguePublish = useForm({ status: 'draft' });

const recoverySummary = computed(() => props.connection?.orders_sync_summary ?? null);
const importSummary = computed(() => props.connection?.catalogue_import_summary ?? null);
const publishSummary = computed(() => props.connection?.catalogue_publish_summary ?? null);
const minRecoveryDate = new Date(Date.now() - 59 * 86400000).toISOString().slice(0, 10);
const today = new Date().toISOString().slice(0, 10);

const summary = computed(() => props.connection?.last_full_sync_summary ?? null);

function save() {
    form.transform((data) => {
        const payload = { ...data, warehouse_id: data.warehouse_id || null };
        if (props.connection?.locations?.length) {
            payload.location_map = Object.entries(mapping)
                .filter(([, value]) => value !== '')
                .map(([locationId, value]) => ({ location_id: locationId, warehouse_id: value === 'all' ? null : value }));
        }
        return payload;
    }).post(`${actions}/settings`, {
        preserveScroll: true,
        onSuccess: () => form.reset('client_secret'),
    });
}

function act(name, method, url, options = {}) {
    busy.value = name;
    router.visit(url, {
        method,
        preserveScroll: true,
        ...options,
        onFinish: () => {
            busy.value = null;
        },
    });
}

function disconnect() {
    if (window.confirm(t('connection.disconnect_confirm'))) {
        act('disconnect', 'delete', `${actions}/connection`);
    }
}

async function copyWebhookUrl() {
    try {
        await navigator.clipboard.writeText(props.webhookUrl);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        // Clipboard unavailable (http, permissions): the URL stays selectable.
    }
}

function formatDate(iso) {
    return iso ? new Date(iso).toLocaleString() : '';
}

const statusVariant = (status) => ({ imported: 'success', failed: 'danger', skipped: 'warning', cancelled: 'neutral' })[status] ?? 'neutral';
</script>

<template>
    <Head :title="title" />

    <AppLayout>
        <template #header>
            <div class="shp-crumbs">
                <span>{{ t('page.crumb') }}</span>
                <span>/</span>
                <strong>{{ title }}</strong>
            </div>
        </template>

        <div class="shp-page">
            <PageHeader :title="t('page.title')" :description="t('page.description')">
                <template #actions>
                    <Badge v-if="connection?.connected" variant="success" dot>
                        {{ t('status.connected', { shop: connection.shop_name }) }}
                    </Badge>
                    <Badge v-else variant="neutral" dot>{{ t('status.not_connected') }}</Badge>
                </template>
            </PageHeader>

            <div v-if="connection?.last_error" class="shp-alert" role="alert">
                <strong>{{ t('status.error') }}:</strong> {{ connection.last_error }}
            </div>
            <div v-if="connection?.missing_scopes?.length" class="shp-alert shp-alert-warning" role="status">
                {{ t('status.missing_scopes', { scopes: connection.missing_scopes.join(', ') }) }}
            </div>

            <form class="shp-grid" @submit.prevent="save">
                <!-- Connection -->
                <Card>
                    <CardHeader :title="t('connection.title')" :subtitle="t('connection.subtitle')" />

                    <div class="shp-fields">
                        <label class="shp-field">
                            <span class="shp-label">{{ t('connection.shop_domain') }}</span>
                            <input v-model="form.shop_domain" class="shp-input" type="text" autocomplete="off" :placeholder="t('connection.shop_domain_hint')" required />
                            <span v-if="form.errors.shop_domain" class="shp-error">{{ form.errors.shop_domain }}</span>
                        </label>

                        <label class="shp-field">
                            <span class="shp-label">{{ t('connection.client_id') }}</span>
                            <input v-model="form.client_id" class="shp-input" type="text" autocomplete="off" required />
                            <span v-if="form.errors.client_id" class="shp-error">{{ form.errors.client_id }}</span>
                        </label>

                        <label class="shp-field">
                            <span class="shp-label">{{ t('connection.client_secret') }}</span>
                            <input
                                v-model="form.client_secret"
                                class="shp-input"
                                type="password"
                                autocomplete="new-password"
                                :placeholder="connection?.has_client_secret ? '••••••••' : ''"
                                :required="!connection?.has_client_secret"
                            />
                            <span v-if="connection?.has_client_secret" class="shp-hint">{{ t('connection.client_secret_saved') }}</span>
                            <span v-if="form.errors.client_secret" class="shp-error">{{ form.errors.client_secret }}</span>
                        </label>

                        <label class="shp-field">
                            <span class="shp-label">{{ t('connection.api_version') }}</span>
                            <input v-model="form.api_version" class="shp-input shp-input-short" type="text" pattern="\d{4}-\d{2}" />
                            <span v-if="form.errors.api_version" class="shp-error">{{ form.errors.api_version }}</span>
                        </label>

                        <div class="shp-field">
                            <span class="shp-hint">{{ t('connection.scopes') }}</span>
                            <code class="shp-scopes">{{ [...requiredScopes, ...optionalScopes].join(',') }}</code>
                            <span class="shp-hint">{{ t('connection.scopes_optional', { scopes: optionalScopes.join(', ') }) }}</span>
                        </div>
                    </div>
                </Card>

                <!-- Sync settings -->
                <Card>
                    <CardHeader :title="t('sync.title')" />

                    <div class="shp-fields">
                        <label class="shp-check">
                            <input v-model="form.import_orders" type="checkbox" />
                            <span>
                                <span class="shp-label">{{ t('sync.import_orders') }}</span>
                                <span class="shp-hint">{{ t('sync.import_orders_hint') }}</span>
                            </span>
                        </label>

                        <label class="shp-check">
                            <input v-model="form.push_inventory" type="checkbox" />
                            <span>
                                <span class="shp-label">{{ t('sync.push_inventory') }}</span>
                                <span class="shp-hint">{{ t('sync.push_inventory_hint') }}</span>
                            </span>
                        </label>

                        <div class="shp-field">
                            <span class="shp-label">{{ t('sync.locations') }}</span>
                            <span class="shp-hint">{{ t('sync.locations_hint') }}</span>
                            <p v-if="!connection?.locations?.length" class="shp-hint">{{ t('sync.location_hint') }}</p>
                            <div v-for="location in connection?.locations ?? []" :key="location.id" class="shp-map-row">
                                <span class="shp-map-name">{{ location.name }}</span>
                                <select v-model="mapping[location.id]" class="shp-input">
                                    <option value="">{{ t('sync.location_unsynced') }}</option>
                                    <option value="all">{{ t('sync.location_all') }}</option>
                                    <option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option>
                                </select>
                            </div>
                            <span v-if="form.errors.location_map" class="shp-error">{{ form.errors.location_map }}</span>
                        </div>

                        <label class="shp-field">
                            <span class="shp-label">{{ t('sync.warehouse') }}</span>
                            <select v-model="form.warehouse_id" class="shp-input">
                                <option value="">{{ t('sync.warehouse_default') }}</option>
                                <option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option>
                            </select>
                            <span class="shp-hint">{{ t('sync.warehouse_hint') }}</span>
                            <span v-if="form.errors.warehouse_id" class="shp-error">{{ form.errors.warehouse_id }}</span>
                        </label>
                    </div>
                </Card>

                <div class="shp-actions">
                    <Button type="submit" :loading="form.processing">{{ t('connection.save') }}</Button>
                    <Button v-if="connection" variant="ghost" :loading="busy === 'disconnect'" @click="disconnect">
                        {{ t('connection.disconnect') }}
                    </Button>
                    <span v-if="connection?.acting_user" class="shp-hint">{{ t('status.acting_user', { name: connection.acting_user }) }}</span>
                </div>
            </form>

            <template v-if="connection">
                <!-- Webhooks -->
                <Card class="shp-section">
                    <CardHeader :title="t('webhooks.title')" :subtitle="t('webhooks.subtitle')">
                        <template #actions>
                            <Button
                                size="sm"
                                :disabled="!connection.connected"
                                :loading="busy === 'webhooks'"
                                @click="act('webhooks', 'post', `${actions}/webhooks`)"
                            >
                                {{ t('webhooks.register') }}
                            </Button>
                        </template>
                    </CardHeader>
                    <div class="shp-url">
                        <code>{{ webhookUrl }}</code>
                        <Button size="xs" variant="outline" @click="copyWebhookUrl">{{ copied ? t('webhooks.copied') : t('webhooks.copy') }}</Button>
                    </div>
                    <p class="shp-hint">
                        {{ connection.webhooks_registered_at ? t('webhooks.registered', { date: formatDate(connection.webhooks_registered_at) }) : t('webhooks.not_registered') }}
                        <template v-if="connection.webhooks_checked_at"> · {{ t('webhooks.checked', { date: formatDate(connection.webhooks_checked_at) }) }}</template>
                    </p>
                </Card>

                <!-- Stock sync -->
                <Card class="shp-section">
                    <CardHeader :title="t('stock.title')" :subtitle="t('stock.subtitle')">
                        <template #actions>
                            <Button
                                size="sm"
                                :disabled="!connection.connected || !connection.push_inventory || !connection.location_map?.length"
                                :loading="busy === 'sync'"
                                @click="act('sync', 'post', `${actions}/sync`)"
                            >
                                {{ t('stock.run') }}
                            </Button>
                        </template>
                    </CardHeader>

                    <p class="shp-hint">
                        {{ connection.last_full_sync_at ? t('stock.last_run', { date: formatDate(connection.last_full_sync_at) }) : t('stock.never') }}
                    </p>

                    <template v-if="summary">
                        <dl class="shp-stats">
                            <div v-for="key in ['shopify_skus', 'inventoros_skus', 'matched', 'pushed', 'failed', 'untracked', 'only_in_shopify', 'only_in_inventoros']" :key="key">
                                <dt>{{ t(`stock.${key}`) }}</dt>
                                <dd>{{ summary[key] ?? 0 }}</dd>
                            </div>
                        </dl>

                        <details v-if="summary.failed_sample?.length" class="shp-details">
                            <summary>{{ t('stock.failed') }} ({{ summary.failed }})</summary>
                            <ul>
                                <li v-for="item in summary.failed_sample" :key="item.sku"><code>{{ item.sku }}</code>: {{ item.error }}</li>
                            </ul>
                        </details>
                        <details v-for="key in ['untracked', 'only_in_shopify', 'only_in_inventoros']" v-show="summary[`${key}_sample`]?.length" :key="key" class="shp-details">
                            <summary>{{ t(`stock.${key}`) }} ({{ summary[key] }})</summary>
                            <p class="shp-hint">{{ t('stock.sample', { count: summary[`${key}_sample`]?.length ?? 0 }) }}</p>
                            <p class="shp-skus">
                                <code v-for="sku in summary[`${key}_sample`] ?? []" :key="sku">{{ sku }}</code>
                            </p>
                        </details>
                    </template>
                </Card>

                <!-- Order recovery -->
                <Card class="shp-section">
                    <CardHeader :title="t('recovery.title')" :subtitle="t('recovery.subtitle')" />
                    <p class="shp-hint">
                        {{ connection.orders_synced_at ? t('recovery.last_run', { date: formatDate(connection.orders_synced_at) }) : t('recovery.never') }}
                    </p>
                    <template v-if="recoverySummary">
                        <dl class="shp-stats">
                            <div v-for="key in ['checked', 'imported', 'updated', 'not_imported']" :key="key">
                                <dt>{{ t(`recovery.${key}`) }}</dt>
                                <dd>{{ recoverySummary[key] ?? 0 }}</dd>
                            </div>
                        </dl>
                        <p v-if="recoverySummary.webhooks_repaired?.length" class="shp-hint">
                            {{ t('recovery.webhooks_repaired', { topics: recoverySummary.webhooks_repaired.join(', ') }) }}
                        </p>
                    </template>
                    <form class="shp-inline" @submit.prevent="recovery.post(`${actions}/recover`, { preserveScroll: true })">
                        <label class="shp-field">
                            <span class="shp-label">{{ t('recovery.from') }}</span>
                            <input v-model="recovery.from" class="shp-input shp-input-short" type="date" :min="minRecoveryDate" :max="today" required />
                            <span class="shp-hint">{{ t('recovery.from_hint') }}</span>
                            <span v-if="recovery.errors.from" class="shp-error">{{ recovery.errors.from }}</span>
                        </label>
                        <Button type="submit" size="sm" :disabled="!connection.connected || !connection.import_orders" :loading="recovery.processing">
                            {{ t('recovery.run') }}
                        </Button>
                    </form>
                </Card>

                <!-- Catalogue -->
                <Card class="shp-section">
                    <CardHeader :title="t('catalogue.title')" :subtitle="t('catalogue.subtitle')" />
                    <div class="shp-columns">
                        <div>
                            <h4 class="shp-subtitle">{{ t('catalogue.import_title') }}</h4>
                            <p class="shp-hint">{{ t('catalogue.import_hint') }}</p>
                            <label class="shp-check shp-gap">
                                <input v-model="catalogueImport.with_stock" type="checkbox" />
                                <span class="shp-label">{{ t('catalogue.with_stock') }}</span>
                            </label>
                            <Button size="sm" :disabled="!connection.connected" :loading="catalogueImport.processing" @click="catalogueImport.post(`${actions}/catalogue/import`, { preserveScroll: true })">
                                {{ t('catalogue.import') }}
                            </Button>
                            <template v-if="importSummary">
                                <p class="shp-hint shp-gap">{{ t('catalogue.last_run', { date: formatDate(importSummary.finished_at) }) }}</p>
                                <dl class="shp-stats shp-stats-compact">
                                    <div v-for="key in ['products', 'variants', 'existing', 'no_sku', 'failed']" :key="key">
                                        <dt>{{ t(`catalogue.${key}`) }}</dt>
                                        <dd>{{ importSummary[key] ?? 0 }}</dd>
                                    </div>
                                </dl>
                                <details v-if="importSummary.failed_sample?.length" class="shp-details">
                                    <summary>{{ t('catalogue.failed') }}</summary>
                                    <ul><li v-for="line in importSummary.failed_sample" :key="line">{{ line }}</li></ul>
                                </details>
                            </template>
                        </div>
                        <div>
                            <h4 class="shp-subtitle">{{ t('catalogue.publish_title') }}</h4>
                            <p class="shp-hint">{{ t('catalogue.publish_hint') }}</p>
                            <select v-model="cataloguePublish.status" class="shp-input shp-input-short shp-gap">
                                <option value="draft">{{ t('catalogue.status_draft') }}</option>
                                <option value="active">{{ t('catalogue.status_active') }}</option>
                            </select>
                            <Button size="sm" :disabled="!connection.connected || !connection.location_map?.length" :loading="cataloguePublish.processing" @click="cataloguePublish.post(`${actions}/catalogue/publish`, { preserveScroll: true })">
                                {{ t('catalogue.publish') }}
                            </Button>
                            <template v-if="publishSummary">
                                <p class="shp-hint shp-gap">{{ t('catalogue.last_run', { date: formatDate(publishSummary.finished_at) }) }}</p>
                                <dl class="shp-stats shp-stats-compact">
                                    <div v-for="key in ['products', 'variants', 'already', 'partial', 'no_sku', 'failed']" :key="key">
                                        <dt>{{ t(`catalogue.${key}`) }}</dt>
                                        <dd>{{ publishSummary[key] ?? 0 }}</dd>
                                    </div>
                                </dl>
                                <details v-if="publishSummary.failed_sample?.length" class="shp-details">
                                    <summary>{{ t('catalogue.failed') }}</summary>
                                    <ul><li v-for="line in publishSummary.failed_sample" :key="line">{{ line }}</li></ul>
                                </details>
                            </template>
                        </div>
                    </div>
                </Card>

                <!-- Orders -->
                <Card class="shp-section" :padded="false">
                    <div class="shp-table-header">
                        <CardHeader :title="t('orders.title')" />
                    </div>
                    <p v-if="!orders.length" class="shp-empty">{{ t('orders.empty') }}</p>
                    <div v-else class="shp-table-wrap">
                        <table class="shp-table">
                            <thead>
                                <tr>
                                    <th>{{ t('orders.shopify_order') }}</th>
                                    <th>{{ t('orders.status') }}</th>
                                    <th>{{ t('orders.result') }}</th>
                                    <th>{{ t('orders.inventoros_order') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="order in orders" :key="order.id">
                                    <td>
                                        <strong>{{ order.name }}</strong>
                                        <span class="shp-hint">{{ formatDate(order.updated_at) }}</span>
                                    </td>
                                    <td><Badge :variant="statusVariant(order.status)" size="sm">{{ t(`orders.statuses.${order.status}`) }}</Badge></td>
                                    <td class="shp-message">{{ order.message }}</td>
                                    <td>
                                        <Link v-if="order.order_url" :href="order.order_url">{{ order.order_number }}</Link>
                                    </td>
                                    <td>
                                        <Button
                                            v-if="order.retryable"
                                            size="xs"
                                            variant="outline"
                                            :loading="busy === `retry-${order.id}`"
                                            @click="act(`retry-${order.id}`, 'post', `${actions}/orders/${order.id}/retry`)"
                                        >
                                            {{ t('orders.retry') }}
                                        </Button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </Card>
            </template>
        </div>
    </AppLayout>
</template>

<style scoped>
.shp-crumbs {
    display: flex;
    gap: 0.5rem;
    font-size: 0.75rem;
    color: hsl(var(--text-tertiary));
}

.shp-crumbs strong {
    font-weight: 500;
    color: hsl(var(--text-primary));
}

.shp-alert {
    margin-top: 1rem;
    padding: 0.75rem 1rem;
    border-radius: 0.5rem;
    border: 1px solid hsl(var(--status-danger) / 0.4);
    background: hsl(var(--status-danger) / 0.08);
    color: hsl(var(--text-primary));
    font-size: 0.875rem;
    overflow-wrap: anywhere;
}

.shp-alert-warning {
    border-color: hsl(var(--status-warning) / 0.4);
    background: hsl(var(--status-warning) / 0.08);
}

.shp-scopes {
    display: block;
    padding: 0.5rem 0.75rem;
    border-radius: 0.5rem;
    background: hsl(var(--surface-sunken));
    color: hsl(var(--text-primary));
    font-size: 0.75rem;
    overflow-wrap: anywhere;
    user-select: all;
}

.shp-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 1rem;
    margin-top: 1.5rem;
}

@media (min-width: 1024px) {
    .shp-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

.shp-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.75rem;
}

@media (min-width: 1024px) {
    .shp-actions {
        grid-column: 1 / -1;
    }
}

.shp-section {
    margin-top: 1rem;
}

.shp-fields {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    margin-top: 1rem;
}

.shp-field {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
}

.shp-label {
    display: block;
    font-size: 0.875rem;
    font-weight: 500;
    color: hsl(var(--text-primary));
}

.shp-hint {
    display: block;
    margin: 0;
    font-size: 0.75rem;
    color: hsl(var(--text-tertiary));
}

.shp-error {
    font-size: 0.75rem;
    color: hsl(var(--status-danger));
}

.shp-input {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border-radius: 0.5rem;
    border: 1px solid hsl(var(--border-strong));
    background: hsl(var(--surface-base));
    color: hsl(var(--text-primary));
    font-size: 0.875rem;
}

.shp-input:focus {
    outline: 2px solid hsl(var(--ring));
    outline-offset: 1px;
}

.shp-input:disabled {
    opacity: 0.6;
}

.shp-input-short {
    max-width: 10rem;
}

.shp-check {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    cursor: pointer;
}

.shp-check input {
    margin-top: 0.2rem;
}

.shp-url {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
    margin: 1rem 0 0.5rem;
}

.shp-url code {
    flex: 1;
    min-width: 0;
    padding: 0.5rem 0.75rem;
    border-radius: 0.5rem;
    background: hsl(var(--surface-sunken));
    color: hsl(var(--text-primary));
    font-size: 0.8125rem;
    overflow-wrap: anywhere;
    user-select: all;
}

.shp-stats {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.75rem;
    margin: 1rem 0 0;
}

@media (min-width: 768px) {
    .shp-stats {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }
}

.shp-stats div {
    padding: 0.75rem;
    border-radius: 0.5rem;
    background: hsl(var(--surface-sunken));
}

.shp-stats dt {
    font-size: 0.75rem;
    color: hsl(var(--text-tertiary));
}

.shp-stats dd {
    margin: 0.25rem 0 0;
    font-size: 1.25rem;
    font-weight: 600;
    color: hsl(var(--text-primary));
}

.shp-details {
    margin-top: 0.75rem;
    font-size: 0.875rem;
    color: hsl(var(--text-secondary));
}

.shp-details summary {
    cursor: pointer;
    color: hsl(var(--text-primary));
}

.shp-details ul {
    margin: 0.5rem 0 0;
    padding-left: 1.25rem;
}

.shp-skus {
    display: flex;
    flex-wrap: wrap;
    gap: 0.375rem;
    margin: 0.5rem 0 0;
}

.shp-skus code,
.shp-details code {
    padding: 0.125rem 0.375rem;
    border-radius: 0.25rem;
    background: hsl(var(--surface-sunken));
    font-size: 0.75rem;
}

.shp-table-header {
    padding: 1.25rem 1.25rem 0;
}

.shp-empty {
    margin: 0;
    padding: 1.25rem;
    font-size: 0.875rem;
    color: hsl(var(--text-tertiary));
}

.shp-table-wrap {
    overflow-x: auto;
    margin-top: 1rem;
}

.shp-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}

.shp-table th {
    padding: 0.5rem 1.25rem;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 500;
    color: hsl(var(--text-tertiary));
    border-bottom: 1px solid hsl(var(--border-subtle));
}

.shp-table td {
    padding: 0.75rem 1.25rem;
    vertical-align: top;
    color: hsl(var(--text-primary));
    border-bottom: 1px solid hsl(var(--border-subtle));
}

.shp-table tr:last-child td {
    border-bottom: none;
}

.shp-table td strong {
    display: block;
    font-weight: 500;
}

.shp-message {
    max-width: 28rem;
    color: hsl(var(--text-secondary)) !important;
    overflow-wrap: anywhere;
}
.shp-map-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1.4fr);
    align-items: center;
    gap: 0.75rem;
}

.shp-map-name {
    font-size: 0.875rem;
    color: hsl(var(--text-primary));
    overflow-wrap: anywhere;
}

.shp-inline {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 0.75rem;
    margin-top: 1rem;
}

.shp-columns {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 1.5rem;
    margin-top: 1rem;
}

@media (min-width: 1024px) {
    .shp-columns {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

.shp-subtitle {
    margin: 0 0 0.25rem;
    font-size: 0.875rem;
    font-weight: 600;
    color: hsl(var(--text-primary));
}

.shp-gap {
    margin: 0.75rem 0;
}

.shp-stats-compact {
    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
}
</style>
