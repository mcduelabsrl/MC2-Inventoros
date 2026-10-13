<script setup>
// Where an order's pairs are committed (warehouse and owner), and what must
// be brought to the shipping warehouse before its single shipment can leave.
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { PackageCheck, ArrowLeftRight } from 'lucide-vue-next';

const { t } = useI18n();

const props = defineProps({
    order: { type: Object, required: true },
    panel: { type: Object, required: true },
});

const busy = ref(false);

const consolidate = () => {
    busy.value = true;
    router.post(route('orders.consolidate', props.order.id), { warehouse_id: props.panel.target_warehouse.id }, {
        preserveScroll: true,
        onFinish: () => (busy.value = false),
    });
};
</script>

<template>
    <Card class="p-4">
        <div class="mb-2 flex items-center gap-2 text-sm font-medium text-text-primary">
            <PackageCheck :size="16" /> {{ t('stockByWarehouse.consolidation.title') }}
        </div>

        <p class="mb-3 text-sm" :class="panel.ready ? 'text-status-success' : 'text-status-warning'">
            {{ panel.ready
                ? t('stockByWarehouse.consolidation.ready', { warehouse: panel.target_warehouse.name })
                : t('stockByWarehouse.consolidation.notReady', { warehouse: panel.target_warehouse.name }) }}
        </p>

        <table class="w-full text-xs">
            <thead>
                <tr class="text-text-tertiary">
                    <th class="text-left">SKU</th>
                    <th class="text-left">{{ t('stockByWarehouse.consolidation.committedIn') }}</th>
                    <th class="text-left">{{ t('stockByWarehouse.owner') }}</th>
                    <th class="text-right">{{ t('stockByWarehouse.committed') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="a in panel.allocations" :key="a.id">
                    <td class="font-mono">{{ a.sku }}</td>
                    <td>{{ a.warehouse }}</td>
                    <td>{{ a.owner || t('stockByWarehouse.unassigned') }}</td>
                    <td class="text-right">
                        {{ a.open }}
                        <Badge v-if="a.shortfall" size="sm" variant="danger">{{ a.shortfall }} {{ t('stockByWarehouse.shortfall') }}</Badge>
                    </td>
                </tr>
            </tbody>
        </table>

        <div v-if="!panel.ready && panel.to_move.length" class="mt-3">
            <div class="mb-1 text-xs font-semibold uppercase text-text-tertiary">{{ t('stockByWarehouse.consolidation.toMove') }}</div>
            <ul class="mb-3 space-y-1 text-xs">
                <li v-for="line in panel.to_move" :key="line.allocation_id">
                    <span class="font-mono">{{ line.sku }}</span> × {{ line.quantity }}: {{ panel.warehouse_names[line.warehouse_id] }} → {{ panel.target_warehouse.name }}
                </li>
            </ul>
            <Button v-if="panel.can_consolidate" size="sm" :disabled="busy" @click="consolidate">
                <ArrowLeftRight :size="14" /> {{ t('stockByWarehouse.consolidation.action', { warehouse: panel.target_warehouse.name }) }}
            </Button>
        </div>
    </Card>
</template>
