<?php

namespace Webkul\Shopify\Services\Bulk\PayloadBuilders;

use Webkul\Shopify\Services\ProductPhaseDataService;
use Webkul\Shopify\Traits\ShopifyGraphqlRequest;

class TranslationsBulkPayloadBuilder
{
    use ShopifyGraphqlRequest;

    protected array $translationFieldMap = [
        'title' => 'title',
        'descriptionHtml' => 'body_html',
        'handle' => 'handle',
        'productType' => 'product_type',
        'metafields_global_title_tag' => 'meta_title',
        'metafields_global_description_tag' => 'meta_description',
    ];

    public function __construct(
        protected ProductPhaseDataService $productPhaseDataService
    ) {}

    /**
     * Build JSONL payload lines for translationsRegister mutation.
     *
     * One product per line with aggregated translations across all locales:
     * {
     *   "resourceId": "gid://shopify/Product/123",
     *   "translations": [
     *     { "key": "title", "value": "...", "locale": "fr" },
     *     { "key": "body_html", "value": "...", "locale": "fr" }
     *   ]
     * }
     *
     * @param  array  $entries  Successful productSet entries
     * @param  int  $credentialId  Credential ID
     * @param  string  $channel  Channel key
     * @param  string  $currency  Currency code
     * @param  array  $storeLocaleMapping  Map: shopifyLocale => unopimLocale
     * @param  array  $storeLocales  Array of locale objects from credential
     * @return array JSONL lines
     */
    public function build(
        array $entries,
        int $credentialId,
        string $channel,
        string $currency,
        array $storeLocaleMapping,
        array $storeLocales
    ): array {
        if (count($storeLocaleMapping) < 2) {
            return [];
        }

        // Determine default shopify locale
        $defaultLanguage = null;
        foreach ($storeLocales as $language) {
            if (! empty($language['defaultlocale'])) {
                $defaultLanguage = $language;
                break;
            }
        }

        $shopifyDefaultLocale = $defaultLanguage
            ? ($storeLocaleMapping[$defaultLanguage['locale']] ?? null)
            : null;

        $lines = [];
        // dump($entries);
        foreach ($entries as $entry) {
            if (! empty($entry['user_errors']) || empty($entry['product']['id'])) {
                continue;
            }

            $productId = $entry['product']['id'];
            $manifest = $entry['manifest'] ?? [];
            $productSku = $manifest['product_sku'] ?? null;

            if (! $productSku) {
                continue;
            }

            [$translations, $metafiel] = $this->buildTranslationsForProduct(
                $productId,
                $productSku,
                $manifest,
                $credentialId,
                $channel,
                $currency,
                $shopifyDefaultLocale,
                $storeLocaleMapping
            );

            // dd

            if (empty($translations)) {
                continue;
            }

            $line = [
                'resourceId' => $this->ensureGid($productId, 'Product'),
                'translations' => $translations,
            ];

            $lines[] = json_encode($line, JSON_UNESCAPED_SLASHES);

            foreach ($metafiel as $metafield) {
                $lines[] = json_encode($metafield, JSON_UNESCAPED_SLASHES);
            }
        }

        return $lines;
    }

    /**
     * Build all translation entries for a single product.
     */
    protected function buildTranslationsForProduct(
        string $productId,
        string $sku,
        array $manifest,
        int $credentialId,
        string $channel,
        string $currency,
        ?string $shopifyDefaultLocale,
        array $storeLocaleMapping
    ): array {
        $translations = [];

        // Fetch product context once per product SKU
        $context = $this->productPhaseDataService->getProductContext(
            $sku,
            $credentialId,
            $channel,
            $currency
        );

        // dd

        $metafieldMap = array_combine(
            array_column($context['product_metafields'] ?? [], 'code'),
            array_column($context['product_metafields'] ?? [], 'name_space_key')
        );
        // dd($context['product_metafields']);
        $queryParts = [];

        foreach ($metafieldMap as $index => $metafield) {
            [$namespace, $key] = explode('.', $metafield, 2);

            $alias = 'mf_'.$index;

            $queryParts[] = <<<GRAPHQL
            {$alias}: metafield(namespace: "{$namespace}", key: "{$key}") {
                id
                namespace
                key
            }
            GRAPHQL;
        }

        $request = [
            'query' => implode("\n", $queryParts),
            'variables' => [
                'id' => $productId,
            ],
        ];

        $metafields = $this->requestGraphQlApiAction('customQuery', $context['credential_array'], $request);
        $metaFields = array_filter($metafields['body']['data']['product'] ?? []) ?? [];

        if (! $context) {
            return [];
        }

        $productData = $context['parent_data'] ?: $context['row_data'];
        $defaultFields = $context['merged_fields'] ?? [];
        // dd($defaultFields);
        $exportMapping = $context['export_mapping']->mapping ?? [];
        $metafieldTranslations = [];
        foreach ($storeLocaleMapping as $shopifyLocaleCode => $unopimLocaleCode) {
            if ($shopifyDefaultLocale === $unopimLocaleCode) {
                continue; // Skip default locale
            }

            $localeFields = $this->productPhaseDataService->getAllAttributeValues(
                $productData,
                $channel,
                $unopimLocaleCode
            );

            foreach (($exportMapping['shopify_connector_settings'] ?? []) as $shopifyField => $unopimField) {
                if (! isset($this->translationFieldMap[$shopifyField])) {
                    continue;
                }

                $translationKey = $this->translationFieldMap[$shopifyField];
                $value = $localeFields[$unopimField] ?? '';
                $defaultValue = $defaultFields[$unopimField] ?? '';

                if (empty($value) || ! is_string($value)) {
                    continue;
                }
                if ($shopifyField == 'handle') {
                    $value = strtolower($value).'-'.$shopifyLocaleCode;
                    $defaultValue = strtolower($defaultValue);
                }
                $translations[] = [
                    'key' => $translationKey,
                    'value' => $value,
                    'locale' => $shopifyLocaleCode,
                    'translatableContentDigest' => hash('sha256', (string) $defaultValue),
                ];
            }

            foreach ($metaFields as $alias => $metafield) {
                if (str_starts_with($alias, 'mf_')) {
                    $attributeCode = substr($alias, 3);
                } else {
                    continue;
                }

                $value = $localeFields[$attributeCode] ?? '';
                if (
                    empty($metafield['id']) ||
                    $value === '' ||
                    ! is_string($value)
                ) {
                    continue;
                }

                $defaultValue = $defaultFields[$attributeCode] ?? '';

                $listValue = collect($context['product_metafields'])
                    ->firstWhere('code', $attributeCode)['listvalue'] ?? null;

                if ($listValue === 1) {
                    $attrType = $context['attributes'][$attributeCode]?->type;
                    if (in_array($attrType, ['multiselect', 'select'])) {
                        $value = $this->getTranslatedOptionLabels($context['attributes'][$attributeCode], $value, $unopimLocaleCode);
                        $value = implode(',', $value);
                        // dump($value);
                        $defaultValue = $this->getTranslatedOptionLabels($context['attributes'][$attributeCode], $localeFields[$attributeCode], $shopifyDefaultLocale);
                        $defaultValue = implode(',', $defaultValue);
                    }

                    $value = array_map('trim', explode(',', $value));
                    $defaultValue = array_map('trim', explode(',', $defaultValue));
                    $defaultValue = json_encode(
                        $defaultValue,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    );
                    $value = json_encode($value);
                }

                $metafieldTranslations[$metafield['id']]['resourceId'] = $metafield['id'];

                $metafieldTranslations[$metafield['id']]['translations'][] = [
                    'key' => 'value',
                    'value' => $value,
                    'locale' => $shopifyLocaleCode,
                    'translatableContentDigest' => hash('sha256', (string) $defaultValue),
                ];
            }
        }

        // dd($metafieldTranslations);
        return [$translations, array_values($metafieldTranslations)];
    }

    /**
     * Get option label from option code
     */
    protected function getTranslatedOptionLabels($attribute, $value, string $locale)
    {
        $values = explode(',', $value);
        $optionTrans = $attribute->options()->whereIn('code', $values)->get()->toArray();
        $translationsArray = array_column($optionTrans, 'translations');
        $translateLabels = array_map(function ($translations, $index) use ($locale, $values) {
            $labelArr = array_column(array_filter($translations, fn ($t) => $t['locale'] === $locale), 'label');
            $label = $labelArr[0] ?? null;

            return ! empty($label) ? $label : $values[$index];
        }, $translationsArray, array_keys($translationsArray));

        return $translateLabels;
    }

    /**
     * Ensure an ID is in Shopify GID format.
     */
    protected function ensureGid(string $id, string $type): string
    {
        if (str_starts_with($id, 'gid://')) {
            return $id;
        }

        return "gid://shopify/{$type}/{$id}";
    }
}
