#!/usr/bin/env bash
set -euo pipefail

RUN_ID="${RUN_ID:-}"
BRANCH="${BRANCH:-$(git branch --show-current)}"
WORKFLOW="${WORKFLOW:-CI}"

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
    runs = gh_json([
        'run', 'list',
        '--workflow', workflow,
        '--branch', branch,
        '--event', 'pull_request',
        '--limit', '10',
        '--json', 'databaseId,status,conclusion',
    ])
    for run in runs:
        if run.get('status') == 'completed' and run.get('conclusion') == 'success':
            run_id = str(run['databaseId'])
            break

if not run_id:
    print('No successful completed pull_request CI run found', file=sys.stderr)
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

# Exclude deploy/build-image skip noise from PR total, but include aggregate browser-tests gate.
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
