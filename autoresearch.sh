#!/usr/bin/env bash
set -euo pipefail

workflow=.github/workflows/ci.yml
if [[ ! -f "$workflow" ]]; then
  echo "workflow missing: $workflow" >&2
  exit 1
fi

python3 - <<'PY'
from __future__ import annotations

import datetime as dt
import json
import math
import re
import statistics
import subprocess
import sys
from pathlib import Path

workflow = Path('.github/workflows/ci.yml')
text = workflow.read_text()

required = ['tests:', 'browser-tests-matrix:', 'linter:', 'static-analysis:', 'performance-tests:', 'build-assets:']
missing = [name for name in required if name not in text]
if missing:
    print(f"missing required jobs: {', '.join(missing)}", file=sys.stderr)
    sys.exit(1)

matrix_match = re.search(r'shard:\s*\[([^\]]+)\]', text)
if matrix_match:
    shards = [part.strip() for part in matrix_match.group(1).split(',') if part.strip()]
else:
    shards = re.findall(r'^\s*-\s+shard:\s*([0-9]+)\s*$', text, re.M)
if not shards:
    print('browser shard matrix missing', file=sys.stderr)
    sys.exit(1)
shard_count = len(shards)

browser_job = re.search(r'(?ms)^  browser-tests-matrix:.*?(?=^  [a-zA-Z0-9_-]+:|\Z)', text)
browser_job_text = browser_job.group(0) if browser_job else ''
browser_depends_on_build = bool(browser_job and re.search(r'^    needs:\s*build-assets\s*$', browser_job_text, re.M))
downloads_build_artifact = 'actions/download-artifact@v4' in browser_job_text
if downloads_build_artifact and not browser_depends_on_build:
    print('browser-tests-matrix downloads build artifact but does not depend on build-assets', file=sys.stderr)
    sys.exit(1)

manual_browser_filters = re.findall(r"filter:\s*'([^']+)'", browser_job_text)
uses_browser_aggregate = 'needs: browser-tests-matrix' in text
job_count = len(re.findall(r'^  [a-zA-Z0-9_-]+:\s*$', text, re.M))

def gh_json(args: list[str]) -> object:
    raw = subprocess.check_output(['gh', *args], text=True, stderr=subprocess.DEVNULL)
    return json.loads(raw)

runs = gh_json(['run', 'list', '--workflow=CI', '--limit=20', '--json=databaseId,conclusion,event,status'])
run_ids = [str(run['databaseId']) for run in runs if run.get('event') == 'pull_request' and run.get('status') == 'completed' and run.get('conclusion') == 'success'][:6]
if len(run_ids) < 3:
    print('need at least 3 successful pull_request CI runs for stable model', file=sys.stderr)
    sys.exit(1)

def seconds(start: str, end: str) -> float:
    s = dt.datetime.fromisoformat(start.replace('Z', '+00:00'))
    e = dt.datetime.fromisoformat(end.replace('Z', '+00:00'))
    return max(0.0, (e - s).total_seconds())

samples: dict[str, list[float]] = {
    'tests': [],
    'linter': [],
    'static-analysis': [],
    'performance-tests': [],
    'build-assets': [],
    'changes': [],
    'browser-aggregate': [],
}
browser_matrix_maxes: list[float] = []
browser_shard_durations: list[float] = []
actual_totals: list[float] = []

for run_id in run_ids:
    payload = gh_json(['run', 'view', run_id, '--json=jobs'])
    jobs = payload.get('jobs', [])
    starts = []
    ends = []
    run_browser_shards = []
    for job in jobs:
        if not job.get('startedAt') or not job.get('completedAt'):
            continue
        name = job['name']
        duration = seconds(job['startedAt'], job['completedAt'])
        starts.append(dt.datetime.fromisoformat(job['startedAt'].replace('Z', '+00:00')))
        ends.append(dt.datetime.fromisoformat(job['completedAt'].replace('Z', '+00:00')))
        if name.startswith('browser-tests-matrix'):
            run_browser_shards.append(duration)
            browser_shard_durations.append(duration)
        elif name in samples:
            samples[name].append(duration)
    if run_browser_shards:
        browser_matrix_maxes.append(max(run_browser_shards))
    if starts and ends:
        actual_totals.append((max(ends) - min(starts)).total_seconds())

required_samples = ['tests', 'linter', 'static-analysis', 'performance-tests', 'build-assets']
missing_samples = [key for key in required_samples if not samples[key]]
if missing_samples or not browser_matrix_maxes:
    print(f"missing historical samples: {', '.join(missing_samples)}", file=sys.stderr)
    sys.exit(1)

median = statistics.median
hist_shards = 4
build_assets = median(samples['build-assets'])
tests = median(samples['tests'])
linter = median(samples['linter'])
static_analysis = median(samples['static-analysis'])
performance_tests = median(samples['performance-tests'])
changes = median(samples['changes']) if samples['changes'] else 5.0
browser_aggregate = median(samples['browser-aggregate']) if samples['browser-aggregate'] else 2.0
browser_current_max = median(browser_matrix_maxes)

if manual_browser_filters:
    log = subprocess.check_output(['gh', 'run', 'view', run_ids[0], '--log'], text=True, stderr=subprocess.DEVNULL, errors='ignore')
    current_class = None
    class_seconds: dict[str, float] = {}
    shard_pest_seconds: list[float] = []
    for line in log.splitlines():
        class_match = re.search(r'PASS\s+(Tests\\\\Browser\\\\\S+)', line)
        if not class_match:
            class_match = re.search(r'PASS\s+(Tests\\Browser\\\S+)', line)
        if class_match:
            current_class = class_match.group(1)
            class_seconds.setdefault(current_class, 0.0)
        test_match = re.search(r'✓ .*?\s+([0-9]+(?:\.[0-9]+)?)s\s*$', line)
        if test_match and current_class:
            class_seconds[current_class] = class_seconds.get(current_class, 0.0) + float(test_match.group(1))
        duration_match = re.search(r'Duration:\s+([0-9]+(?:\.[0-9]+)?)s', line)
        if duration_match:
            shard_pest_seconds.append(float(duration_match.group(1)))

    if not class_seconds or not shard_pest_seconds:
        print('unable to derive browser class timings from recent CI logs', file=sys.stderr)
        sys.exit(1)

    observed_overhead = max(60.0, browser_current_max - median(shard_pest_seconds))
    estimated_filter_seconds = []
    covered_classes: set[str] = set()
    for filter_expression in manual_browser_filters:
        classes = [class_name.replace('\\\\', '\\') for class_name in re.findall(r'Tests\\\\Browser\\\\[A-Za-z0-9_]+', filter_expression)]
        if not classes:
            print(f'empty browser filter: {filter_expression}', file=sys.stderr)
            sys.exit(1)
        covered_classes.update(classes)
        estimated_filter_seconds.append(sum(class_seconds.get(class_name, 0.0) for class_name in classes))

    missing_classes = set(class_seconds) - covered_classes
    if missing_classes:
        print(f'manual browser filters omit classes: {sorted(missing_classes)}', file=sys.stderr)
        sys.exit(1)

    estimated_browser_matrix = observed_overhead + max(estimated_filter_seconds)
else:
    # Browser tests dominate PR time. Estimate shard-count changes conservatively:
    # keep fixed Playwright/setup overhead, scale only test work, preserve observed skew.
    setup_floor = min(120.0, max(60.0, median(browser_shard_durations) * 0.35))
    work_current = max(1.0, browser_current_max - setup_floor)
    estimated_browser_matrix = setup_floor + work_current * hist_shards / shard_count
    estimated_browser_matrix = max(setup_floor, estimated_browser_matrix)

independent = max(tests, linter, static_analysis, performance_tests, changes)
if browser_depends_on_build:
    browser_path = build_assets + estimated_browser_matrix + (browser_aggregate if uses_browser_aggregate else 0.0)
else:
    browser_path = estimated_browser_matrix + (browser_aggregate if uses_browser_aggregate else 0.0)
ci_total = max(independent, build_assets, browser_path)

actual_median_total = median(actual_totals) if actual_totals else ci_total

metrics = {
    'ci_total_s': ci_total,
    'actual_recent_pr_total_s': actual_median_total,
    'build_assets_s': build_assets,
    'tests_s': tests,
    'browser_matrix_s': estimated_browser_matrix,
    'linter_s': linter,
    'static_analysis_s': static_analysis,
    'performance_tests_s': performance_tests,
    'job_count': float(job_count),
    'browser_shards': float(shard_count),
}
for key, value in metrics.items():
    print(f"METRIC {key}={value:.3f}")
PY
