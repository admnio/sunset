import { ref, computed, unref } from 'vue';

/**
 * useFilter — reactive text filter over a list of rows.
 *
 *   const rows = ref([{ name: 'Foo', queue: 'default' }, …]);
 *   const { query, filtered, count } = useFilter(rows, ['name', 'queue']);
 *
 * Args
 *   source  Ref<Array> | Array — rows to filter (auto-unwrapped each tick)
 *   keys    string[]           — property names to search; case-insensitive substring
 *
 * Returns
 *   query     Ref<string>      — bound to a SearchInput's v-model
 *   filtered  ComputedRef<Array>
 *   count     ComputedRef<number>   shorthand for filtered.value.length
 */
export function useFilter(source, keys = []) {
  const query = ref('');

  const filtered = computed(() => {
    const rows = unref(source) || [];
    const q = query.value.trim().toLowerCase();
    if (! q) return rows;
    return rows.filter((row) =>
      keys.some((k) => String(row?.[k] ?? '').toLowerCase().includes(q))
    );
  });

  const count = computed(() => filtered.value.length);

  return { query, filtered, count };
}
