// Shared formatters for Sunset activity-stream events. Imported by both the
// Activity page and the Overview "Recent activity" preview so their pill
// colors, type-stamp labels, and human summaries stay identical — previously
// each view kept its own copy and they drifted apart.

export function eventPillStatus(t) {
  if (t === 'job_failed' || t === 'unable_to_launch_process') return 'err';
  if (t === 'job_rate_limited' || t === 'long_wait_detected' || t === 'worker_process_restarting') return 'warn';
  if (t === 'job_completed' || t === 'master_supervisor_deployed') return 'ok';
  if (t === 'queue_paused' || t === 'queue_resumed') return 'info';
  return 'info';
}

// Concise type-stamp labels. Kept short so the pill never overflows the
// log-entry's fixed type column. Unknown types fall back to a title-cased
// form of the raw event key.
const EVENT_LABELS = {
  job_failed:                 'Failed',
  unable_to_launch_process:   'Launch fail',
  job_rate_limited:           'Rate limited',
  job_completed:              'Completed',
  job_queued:                 'Queued',
  worker_process_restarting:  'Restart',
  master_supervisor_deployed: 'Deployed',
  long_wait_detected:         'Long wait',
  queue_paused:               'Paused',
  queue_resumed:              'Resumed',
};

export function eventTitle(t) {
  return EVENT_LABELS[t] ?? (t || '').replace(/_/g, ' ').replace(/\b\w/g, (m) => m.toUpperCase());
}

export function eventSummary(e) {
  const p = e.payload ?? {};
  switch (e.type) {
    case 'job_failed':
      return `${p.job_class ?? p.job_id ?? 'unknown job'} on ${p.queue}`;
    case 'job_completed':
      return `${p.job_class ?? p.job_id} on ${p.queue}${p.duration_ms != null ? ` (${p.duration_ms}ms)` : ''}`;
    case 'job_rate_limited':
      return `${p.job_class ?? p.job_id} on ${p.queue} hit ${p.limit_name} (${p.strategy}, retry after ${p.retry_after}s)`;
    case 'job_queued':
      return `${p.job_class ?? p.job_id} → ${p.connection}:${p.queue}`;
    case 'worker_process_restarting':
      return `worker pid ${p.pid ?? '?'} restarting`;
    case 'unable_to_launch_process':
      return `failed to launch worker pid ${p.pid ?? '?'}: ${p.command ?? '(no command)'}`;
    case 'long_wait_detected':
      return `${p.connection}:${p.queue} idle ${p.seconds}s`;
    case 'master_supervisor_deployed':
      return `master ${p.master_name} deployed`;
    case 'queue_paused':
      return `${p.connection}:${p.queue} paused${p.actor ? ` by ${p.actor}` : ''}`;
    case 'queue_resumed':
      return `${p.connection}:${p.queue} resumed${p.actor ? ` by ${p.actor}` : ''}`;
    default:
      return e.type;
  }
}

// Optional secondary line rendered under the summary (as `.detail`). For
// failures this carries the exception class + message so it gets its own line
// instead of being crammed onto the summary. Returns '' when there's nothing
// to show.
export function eventDetail(e) {
  const p = e.payload ?? {};
  if (e.type === 'job_failed') {
    return `${p.exception_class ?? 'exception'}${p.exception_message ? ' — ' + p.exception_message : ''}`;
  }
  return '';
}
