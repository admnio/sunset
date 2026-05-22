<script setup>
/**
 * RangeGroup — single-select chip group for time ranges (or any small enum).
 *
 *   <RangeGroup v-model="range" :options="['15m','1h','6h','24h','7d']" />
 *
 * `options` is either a string[] or [{ value, label }] for cases where the
 * displayed label differs from the v-model value.
 */
import { computed } from 'vue';

const props = defineProps({
  modelValue: { type: [String, Number], default: null },
  options:    { type: Array, required: true },
  ariaLabel:  { type: String, default: 'Time range' },
});
const emit = defineEmits(['update:modelValue']);

const normalized = computed(() =>
  props.options.map((o) =>
    typeof o === 'object' && o !== null ? { value: o.value, label: o.label ?? String(o.value) }
                                        : { value: o, label: String(o) }
  )
);

function pick(value) {
  emit('update:modelValue', value);
}
</script>

<template>
  <div class="range-group" role="radiogroup" :aria-label="ariaLabel">
    <button
      v-for="opt in normalized"
      :key="opt.value"
      type="button"
      role="radio"
      :aria-checked="modelValue === opt.value"
      :class="['chip', modelValue === opt.value ? 'active' : '']"
      @click="pick(opt.value)"
    >{{ opt.label }}</button>
  </div>
</template>
