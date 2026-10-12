<script setup>
// One card in the grid of the Settings hub, linking to the Shopify page.
import { t } from './i18n.js';

const {
    ui: { Card },
    Inertia: { Link },
} = window.Inventoros;

defineProps({
    url: { type: String, required: true },
});
</script>

<template>
    <Link :href="url">
        <Card hoverable>
            <div class="shp-card">
                <span class="shp-card-icon" aria-hidden="true">S</span>
                <div class="shp-card-body">
                    <h3 class="shp-card-title">{{ t('card.title') }}</h3>
                    <p class="shp-card-text">{{ t('card.description') }}</p>
                </div>
            </div>
        </Card>
    </Link>
</template>

<style scoped>
.shp-card {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
}

.shp-card-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 0.5rem;
    background: hsl(var(--accent-soft));
    color: hsl(var(--accent));
    font-weight: 700;
}

.shp-card-body {
    min-width: 0;
    flex: 1;
}

.shp-card-title {
    margin: 0;
    font-size: 0.875rem;
    font-weight: 600;
    color: hsl(var(--text-primary));
}

.shp-card-text {
    margin: 0.25rem 0 0;
    font-size: 0.875rem;
    color: hsl(var(--text-secondary));
}
</style>
