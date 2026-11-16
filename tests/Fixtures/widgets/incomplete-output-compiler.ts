// Stands in for scripts/widgets/compile-widget.ts in WidgetCompilerTest: a
// compiler that answers a successful compile without its module or timings.
process.stdout.write(JSON.stringify({ ok: true, errors: [] }));
