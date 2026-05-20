<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { usePolling } from '../composables/usePolling.js';
import Empty from '../components/Empty.vue';

const page = usePage();
const initial = page.props;
const { data } = usePolling(page.url);
const current = computed(() => data.value ?? initial);
const limits = computed(() => current.value.limits ?? []);
const rejects = computed(() => current.value.rejects ?? []);
</script>

<template>
  <div class="space-y-3">
    <h1 class="text-base font-bold">Rate limits</h1>
    <p class="text-xs text-sunset-muted">
      Read-only. Declared via <code>Sunset::for()</code> / <code>Sunset::limit()</code> in service providers.
    </p>

    <Empty v-if="limits.length === 0" message="No rate limits declared." />
    <div v-else class="border border-sunset-border rounded overflow-hidden">
      <div
        class="grid bg-sunset-rail text-sunset-muted text-[9px] uppercase tracking-wide px-3 py-1 gap-3"
        style="grid-template-columns: 1fr 1fr 1fr 1fr 100px"
      >
        <div>Name</div>
        <div>Target</div>
        <div>Throttle</div>
        <div>Concurrency</div>
        <div>Over-limit</div>
      </div>
      <div
        v-for="limit in limits"
        :key="limit.name"
        class="grid px-3 py-2 border-t border-sunset-border text-xs gap-3"
        style="grid-template-columns: 1fr 1fr 1fr 1fr 100px"
      >
        <div class="font-bold truncate">{{ limit.name }}</div>
        <div class="text-sunset-muted truncate">{{ limit.target }}</div>
        <div class="tabular-nums">
          <span v-if="limit.throttle">{{ limit.throttle.max }} / {{ limit.throttle.window }}s</span>
          <span v-else class="text-sunset-muted">—</span>
        </div>
        <div class="tabular-nums">
          <span v-if="limit.concurrency">{{ limit.concurrency.max }} slots</span>
          <span v-else class="text-sunset-muted">—</span>
        </div>
        <div class="text-sunset-muted truncate">{{ limit.over_limit || '—' }}</div>
      </div>
    </div>

    <section v-if="rejects.length" class="space-y-2">
      <h2 class="text-xs uppercase text-sunset-muted">Recent rejections</h2>
      <div class="border border-sunset-border rounded divide-y divide-sunset-border">
        <div
          v-for="row in rejects"
          :key="`${row.connection}:${row.queue}:${row.limit}`"
          class="px-3 py-2 grid gap-3 text-xs"
          style="grid-template-columns: 1fr 1fr 1fr 120px 80px"
        >
          <div class="text-sunset-text font-bold truncate">{{ row.limit }}</div>
          <div class="text-sunset-muted truncate">{{ row.connection }}/{{ row.queue }}</div>
          <div></div>
          <div class="text-sunset-text text-right tabular-nums">{{ row.count }}</div>
          <div class="text-sunset-muted text-right">~{{ row.ttl_seconds }}s</div>
        </div>
      </div>
    </section>
  </div>
</template>
