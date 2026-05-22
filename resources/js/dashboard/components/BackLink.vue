<script setup>
/**
 * BackLink — small back-arrow link, Inertia-routed.
 *
 *   <BackLink to="/sunset/metrics">Back to Metrics</BackLink>
 *
 * Renders the `.back-link` chrome (left-arrow icon + muted label) and routes
 * via Inertia. Falls back to an `<a>` tag when `to` is an absolute URL or
 * external link.
 */
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
  to: { type: String, required: true },
});

const isExternal = computed(() => /^https?:/i.test(props.to));
</script>

<template>
  <a v-if="isExternal" :href="to" class="back-link">
    <svg aria-hidden="true"><use href="#i-arrow-right"/></svg>
    <slot />
  </a>
  <Link v-else :href="to" class="back-link">
    <svg aria-hidden="true"><use href="#i-arrow-right"/></svg>
    <slot />
  </Link>
</template>
