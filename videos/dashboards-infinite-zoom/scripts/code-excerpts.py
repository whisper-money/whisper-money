"""Prints the code each cycle of the film types, as JSON keyed by variable id.

Every line is copied verbatim from the widget source the local agent saved
(assets/widgets/<widget>.<locale>.tsx.txt), so what the film shows is the
code that draws the widget it lands on. Only leading indentation is trimmed.

Usage: python3 scripts/code-excerpts.py en|es
"""

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

PICKS = {
    "code_1": ("takeaway", [
        "export default function",
        "const { orders } = useWidgetData();",
        "for (const row of orders.data) {",
        "const weekday =",
        "totals.at(weekday).total +=",
        "const weekend = days",
        "<h3 className=",
        "WEEKEND.includes(index) ? 'var(--color-amber-500)'",
    ]),
    "code_2": ("car", [
        "const BORROWED = 1800000;",
        "export default function",
        "const { payments } = useWidgetData();",
        "const paid = rows.reduce(",
        "const left = Math.max(BORROWED - paid, 0);",
        "const paymentsLeft = instalment > 0",
        "return { left, paymentsLeft, points, payoff",
        "<h3 className=",
    ]),
    "code_3": ("kids", [
        "export default function",
        "const { activities } = useWidgetData();",
        "for (const bucket of activities.data.buckets) {",
        "const term = termOf(bucket.month);",
        "sums.set(bucket.label_id,",
        "<h3 className=",
        "stackId=\"kids\"",
        "<Legend iconType=",
    ]),
}


def excerpt(widget: str, locale: str, needles: list[str]) -> str:
    lines = (ROOT / "assets" / "widgets" / f"{widget}.{locale}.tsx.txt").read_text().splitlines()
    picked = []
    for needle in needles:
        match = next(line for line in lines if needle in line)
        picked.append(match.strip())
    return "\n".join(picked)


locale = sys.argv[1]
print(json.dumps({key: excerpt(widget, locale, needles) for key, (widget, needles) in PICKS.items()}, ensure_ascii=False, indent=2))
