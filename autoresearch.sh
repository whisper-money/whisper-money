#!/usr/bin/env bash
set -euo pipefail

RUN_ID="${RUN_ID:-}"
BRANCH="${BRANCH:-$(git branch --show-current)}"
WORKFLOW="${WORKFLOW:-CI}"

current_head() {
  git rev-parse HEAD
}

latest_run_for_head() {
  local event="$1"
  local head_sha="$2"
  gh run list --workflow="$WORKFLOW" --branch="$BRANCH" --event="$event" --limit=20 --json databaseId,headSha,status,conclusion \
    --jq ".[] | select(.headSha == \"$head_sha\") | .databaseId" | head -1
}

wait_for_run_for_head() {
  local event="$1"
  local head_sha="$2"
  local run_id=""

  for _ in {1..90}; do
    run_id="$(latest_run_for_head "$event" "$head_sha")"
    if [[ -n "$run_id" ]]; then
      echo "$run_id"
      return 0
    fi
    sleep 5
  done

  echo "No $event CI run appeared for $head_sha" >&2
  return 1
}

commit_and_push_if_dirty() {
  local message="$1"

  if ! git diff --quiet -- .github/workflows composer.json composer.lock tests/.pest autoresearch.md autoresearch.sh autoresearch.jsonl || \
     [[ -n "$(git ls-files --others --exclude-standard .github/workflows tests/.pest 2>/dev/null)" ]]; then
    git add .github/workflows composer.json composer.lock tests/.pest autoresearch.md autoresearch.sh autoresearch.jsonl
    git commit -m "$message"
    git push
  fi
}

# Time-balanced sharding experiment bootstrap:
# 1. Push Pest/update-shards workflow changes.
# 2. Run CI workflow_dispatch with build_only=false to produce tests/.pest/shards.json.
# 3. Download and commit shards.json.
# 4. Wait for PR CI for the committed shard timings and measure it.
if [[ -z "$RUN_ID" && ! -f tests/.pest/shards.json && "${GENERATE_BROWSER_SHARDS:-1}" == "1" ]]; then
  commit_and_push_if_dirty "Experiment: update Pest and enable browser shard timings"

  head_sha="$(current_head)"
  dispatch_run="$(latest_run_for_head workflow_dispatch "$head_sha")"
  if [[ -z "$dispatch_run" ]]; then
    gh workflow run "$WORKFLOW" --ref "$BRANCH" -f build_only=false
    dispatch_run="$(wait_for_run_for_head workflow_dispatch "$head_sha")"
  fi

  gh run watch "$dispatch_run" --exit-status --interval 10 >/dev/null

  tmp_dir="$(mktemp -d)"
  gh run download "$dispatch_run" --name browser-shards --dir "$tmp_dir" >/dev/null
  mkdir -p tests/.pest
  cp "$tmp_dir/shards.json" tests/.pest/shards.json
  rm -rf "$tmp_dir"

  commit_and_push_if_dirty "Experiment: add Pest browser shard timings"
  head_sha="$(current_head)"
  RUN_ID="$(wait_for_run_for_head pull_request "$head_sha")"
  gh run watch "$RUN_ID" --exit-status --interval 10 >/dev/null
elif [[ -z "$RUN_ID" ]]; then
  commit_and_push_if_dirty "Experiment CI change"
  head_sha="$(current_head)"
  RUN_ID="$(wait_for_run_for_head pull_request "$head_sha")"
  gh run watch "$RUN_ID" --exit-status --interval 10 >/dev/null
fi

python3 - <<'PY' "$RUN_ID" "$BRANCH" "$WORKFLOW"
from __future__ import annotations

import datetime as dt
import json
import subprocess
import sys
from collections import defaultdict

run_id, branch, workflow = sys.argv[1:4]


def gh_json(args: list[str]) -> object:
    raw = subprocess.check_output(['gh', *args], text=True, stderr=subprocess.DEVNULL)
    return json.loads(raw)

if not run_id:
    head_sha = subprocess.check_output(['git', 'rev-parse', 'HEAD'], text=True).strip()
    runs = gh_json([
        'run', 'list',
        '--workflow', workflow,
        '--branch', branch,
        '--event', 'pull_request',
        '--limit', '20',
        '--json', 'databaseId,status,conclusion,headSha',
    ])
    for run in runs:
        if run.get('headSha') == head_sha and run.get('status') == 'completed' and run.get('conclusion') == 'success':
            run_id = str(run['databaseId'])
            break

if not run_id:
    print('No successful completed pull_request CI run found for HEAD', file=sys.stderr)
    sys.exit(1)

payload = gh_json(['run', 'view', run_id, '--json', 'jobs,status,conclusion,headSha,headBranch,event'])
if payload.get('status') != 'completed' or payload.get('conclusion') != 'success':
    print(f'Run {run_id} not successful: {payload.get("status")} {payload.get("conclusion")}', file=sys.stderr)
    sys.exit(1)


def parse_time(value: str) -> dt.datetime:
    return dt.datetime.fromisoformat(value.replace('Z', '+00:00'))


def seconds(start: str, end: str) -> float:
    return max(0.0, (parse_time(end) - parse_time(start)).total_seconds())

jobs = [job for job in payload.get('jobs', []) if job.get('startedAt') and job.get('completedAt')]
if not jobs:
    print(f'Run {run_id} has no timed jobs', file=sys.stderr)
    sys.exit(1)

measured_jobs = [
    job for job in jobs
    if job.get('conclusion') != 'skipped' or job['name'] in {'build-assets'}
]
measured_jobs = [
    job for job in measured_jobs
    if not job['name'].startswith('build-image') and not job['name'].startswith('deploy')
]

start = min(parse_time(job['startedAt']) for job in measured_jobs)
end = max(parse_time(job['completedAt']) for job in measured_jobs)
total = (end - start).total_seconds()

buckets: dict[str, list[float]] = defaultdict(list)
for job in jobs:
    name = job['name']
    duration = seconds(job['startedAt'], job['completedAt'])
    if name == 'tests':
        buckets['tests_s'].append(duration)
    elif name == 'linter':
        buckets['linter_s'].append(duration)
    elif name == 'static-analysis':
        buckets['static_analysis_s'].append(duration)
    elif name == 'performance-tests':
        buckets['performance_tests_s'].append(duration)
    elif name == 'build-assets':
        buckets['build_assets_s'].append(duration if job.get('conclusion') != 'skipped' else 0.0)
    elif name == 'browser-tests':
        buckets['browser_aggregate_s'].append(duration)
    elif name.startswith('browser-tests-matrix'):
        buckets['browser_matrix_shard_s'].append(duration)
    elif name == 'update-browser-shards':
        buckets['update_browser_shards_s'].append(duration)

browser_shards = buckets['browser_matrix_shard_s']
metrics = {
    'github_ci_total_s': total,
    'tests_s': max(buckets['tests_s'] or [0.0]),
    'linter_s': max(buckets['linter_s'] or [0.0]),
    'static_analysis_s': max(buckets['static_analysis_s'] or [0.0]),
    'performance_tests_s': max(buckets['performance_tests_s'] or [0.0]),
    'build_assets_s': max(buckets['build_assets_s'] or [0.0]),
    'browser_matrix_s': max(browser_shards or [0.0]),
    'browser_aggregate_s': max(buckets['browser_aggregate_s'] or [0.0]),
    'browser_shards': float(len(browser_shards)),
    'job_count': float(len(jobs)),
    'run_id': float(run_id),
}

for key, value in metrics.items():
    print(f'METRIC {key}={value:.3f}')
PY
