<x-mcp::app :title="$title" class="bg-background text-foreground antialiased">
    <x-slot:head>
        @if ($style !== null)
            <style>{!! $style !!}</style>
        @endif
        @if ($script !== null)
            <script type="module">{!! $script !!}</script>
        @endif
    </x-slot:head>

    @if ($script === null)
        <p style="font-family: sans-serif; padding: 1rem;">The widget preview is not available right now. The widget is saved: open it on the dashboard.</p>
    @else
        <div id="widget-preview"></div>
    @endif
</x-mcp::app>
