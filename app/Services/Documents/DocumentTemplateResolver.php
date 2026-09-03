<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Enums\FulfillmentDocumentType;
use App\Models\DocumentTemplate;
use App\Models\Organization;
use InvalidArgumentException;

/**
 * Picks the template a document should render with:
 *
 *   1. the system default for `$documentType` from config/documents.php
 *      (always present — this is the "if no custom template exists" path);
 *   2. if the type is customizable AND an active DocumentTemplate row exists
 *      for the organization (a store-scoped row wins over an org-wide one),
 *      its partial `settings` are deep-merged over the default.
 *
 * The Blade view stays the system one unless a future editor sets a custom
 * body (not wired yet). Provider PDFs never reach this resolver.
 */
class DocumentTemplateResolver
{
    public function resolve(string $documentType, ?Organization $organization = null, ?string $storeId = null): ResolvedDocumentTemplate
    {
        $default = config("documents.templates.{$documentType}");

        if (! is_array($default) || ! isset($default['view'])) {
            throw new InvalidArgumentException("No system document template is defined for type '{$documentType}'.");
        }

        $defaultSettings = is_array($default['settings'] ?? null) ? $default['settings'] : [];
        $custom = $this->findCustom($documentType, $organization, $storeId);

        $settings = $custom !== null
            ? $this->deepMerge($defaultSettings, is_array($custom->settings) ? $custom->settings : [])
            : $defaultSettings;

        return new ResolvedDocumentTemplate(
            documentType: $documentType,
            view: (string) $default['view'],
            settings: $settings,
            isCustom: $custom !== null,
            templateId: $custom?->id,
        );
    }

    private function findCustom(string $documentType, ?Organization $organization, ?string $storeId): ?DocumentTemplate
    {
        if ($organization === null || ! in_array($documentType, FulfillmentDocumentType::customizableValues(), true)) {
            return null;
        }

        return DocumentTemplate::query()
            ->where('organization_id', $organization->id)
            ->where('document_type', $documentType)
            ->where('is_active', true)
            ->where(function ($q) use ($storeId) {
                $q->whereNull('store_id');

                if ($storeId !== null) {
                    $q->orWhere('store_id', $storeId);
                }
            })
            // A store-specific override beats the org-wide one.
            ->orderByRaw('store_id is null')
            ->first();
    }

    /**
     * Recursive merge: scalars and lists from $override replace $base;
     * associative arrays merge key-by-key.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function deepMerge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (
                is_array($value)
                && ! array_is_list($value)
                && isset($base[$key])
                && is_array($base[$key])
                && ! array_is_list($base[$key])
            ) {
                $base[$key] = $this->deepMerge($base[$key], $value);

                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }
}
