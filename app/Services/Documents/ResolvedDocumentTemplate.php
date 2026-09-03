<?php

declare(strict_types=1);

namespace App\Services\Documents;

/**
 * The concrete template a document will be rendered with: a Blade view plus
 * a fully-merged settings bag (system defaults from config/documents.php,
 * with any active DocumentTemplate row's partial override applied on top).
 *
 * Immutable — produced by DocumentTemplateResolver, consumed by
 * DocumentRenderer and by the Blade view itself.
 */
final class ResolvedDocumentTemplate
{
    /**
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public readonly string $documentType,
        public readonly string $view,
        public readonly array $settings,
        public readonly bool $isCustom,
        public readonly ?string $templateId = null,
    ) {}

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    /** @return array<int, string> */
    public function visibleFields(): array
    {
        $fields = $this->settings['visible_fields'] ?? [];

        return is_array($fields) ? array_values(array_filter($fields, 'is_string')) : [];
    }

    public function fieldVisible(string $field): bool
    {
        return in_array($field, $this->visibleFields(), true);
    }

    public function label(string $key, string $default = ''): string
    {
        $value = data_get($this->settings, "labels.{$key}", $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    public function barcodePosition(): string
    {
        $pos = data_get($this->settings, 'barcode.position', 'header');

        return in_array($pos, ['header', 'footer', 'none'], true) ? $pos : 'header';
    }

    public function barcodeType(): string
    {
        $type = data_get($this->settings, 'barcode.type', 'C128B');

        return is_string($type) && $type !== '' ? $type : 'C128B';
    }
}
