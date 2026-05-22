<script setup>
/**
 * SearchInput — text input with leading search icon, styled via `.search-input`.
 *
 *   <SearchInput v-model="q" placeholder="Filter by class…" />
 */
defineProps({
  modelValue: { type: String, default: '' },
  placeholder: { type: String, default: 'Filter…' },
  ariaLabel:   { type: String, default: 'Filter' },
});
defineEmits(['update:modelValue']);
</script>

<template>
  <label class="search-input">
    <svg aria-hidden="true"><use href="#i-search"/></svg>
    <input
      type="search"
      :value="modelValue"
      :placeholder="placeholder"
      :aria-label="ariaLabel"
      @input="$emit('update:modelValue', $event.target.value)"
    />
  </label>
</template>
