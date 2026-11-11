// Stands in for scripts/widgets/compile-widget.ts in WidgetCompilerTest: a
// compiler that answers valid JSON in the wrong shape.
process.stdout.write(JSON.stringify({ hello: 'world' }));
