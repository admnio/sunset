<script setup>
/**
 * BulkActionBar — sticky floating action bar shown when items are selected.
 *
 *   <BulkActionBar v-if="selected.length" :count="selected.length" @clear="selected = []">
 *     <button class="btn sm" @click="retryAll">Retry selected</button>
 *     <button class="btn sm danger" @click="deleteAll">Delete</button>
 *   </BulkActionBar>
 *
 * Built-in "Clear" emits `@clear`. The default slot is the action buttons,
 * rendered right-aligned via `.bulk-bar .actions`.
 */
defineProps({
  count: { type: Number, required: true },
  label: { type: String, default: 'selected' },
});
defineEmits(['clear']);
</script>

<template>
  <div class="bulk-bar visible" role="region" aria-label="Bulk actions">
    <span><span class="count">{{ count }}</span> {{ label }}</span>
    <span class="sep">·</span>
    <button class="btn ghost sm" type="button" @click="$emit('clear')">Clear</button>
    <span class="actions">
      <slot />
    </span>
  </div>
</template>
