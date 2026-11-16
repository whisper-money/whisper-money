<?php

namespace App\Services\Widgets;

/**
 * What one revision of a user widget holds, once checked: its manifest as
 * validated, its TSX and the module that TSX compiled to.
 */
final readonly class WidgetContent
{
    /**
     * @param  array{name: string, size: array<string, mixed>, data: array<string, array{endpoint: string, params?: array<string, mixed>, paginate?: 'all'}>}  $manifest
     */
    public function __construct(
        public array $manifest,
        private string $source,
        private string $js,
    ) {}

    /**
     * The columns of a revision, which the definition repeats for its current one.
     *
     * @return array{manifest: array<string, mixed>, source: string, compiled_js: string}
     */
    public function attributes(): array
    {
        return ['manifest' => $this->manifest, 'source' => $this->source, 'compiled_js' => $this->js];
    }
}
