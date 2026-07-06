<?php

namespace Webkul\Shopify\Services\Bulk\PayloadBuilders\Core;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\DAM\Models\Directory;
use Webkul\DAM\Repositories\AssetRepository;
use Webkul\DataTransfer\Contracts\JobTrack as JobTrackContract;
use Webkul\DataTransfer\Helpers\Export;
use Webkul\Product\Services\ProductValueMapper;
use Webkul\Shopify\Exceptions\InvalidCredential;
use Webkul\Shopify\Helpers\Exporters\Product\ShopifyGraphQLDataFormatter;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Repositories\ShopifyExportMappingRepository;
use Webkul\Shopify\Repositories\ShopifyMappingRepository;
use Webkul\Shopify\Repositories\ShopifyMetaFieldRepository;
use Webkul\Shopify\Services\Bulk\Files\FileReferenceUploader;
use Webkul\Shopify\Services\Bulk\Media\AssetUrlResolver;
use Webkul\Shopify\Services\Bulk\PayloadBuilders\MediaBulkPayloadBuilder;
use Webkul\Shopify\Services\BulkOperationService;
use Webkul\Shopify\Traits\ShopifyGraphqlRequest;

class CoreProductBulkPayloadBuilder
{
    use ShopifyGraphqlRequest;

    /**
     * entityType under which per-image media content hashes are stored.
     *
     * Media lives on the parent product in Shopify, so one media-hash row is
     * written per product image:
     *   - code          => hash of a single image's content
     *   - relatedSource => parent product SKU
     *   - relatedId     => parent product id
     *   - apiUrl        => shop URL
     *
     * The full set of hashes for a parent SKU is the sole signal used to detect
     * whether the product's media has already been exported (no Shopify media ids
     * are relied upon). If any image hash is added, removed or changed, the media
     * is considered modified and the complete set is re-exported via productSet.
     */
    protected const MEDIA_HASH_ENTITY_TYPE = 'productMediaHash';

    protected array $imageMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/jpg'];

    /**
     * Separator joining the attribute code and image path inside `code`.
     */
    protected const CODE_SEPARATOR = '|';

    protected array $attributesAll = [];

    protected ?string $taxonomyAttributeCode = null;

    protected ?array $metafieldCategoryConstraintsMap = null;

    protected array $credentialAsArray = [];

    protected mixed $credential = null;

    protected mixed $exportMapping = null;

    protected mixed $settingMapping = null;

    protected array $productMetaFieldMapping = [];

    protected array $variantMetaFieldMapping = [];

    protected ?string $shopifyDefaultLocale = null;

    protected ?string $locationId = null;

    protected ?string $currency = null;

    protected ?string $jobChannel = null;

    protected bool $assetRepositoryResolved = false;

    protected ?AssetRepository $resolvedAssetRepository = null;

    public function __construct(
        protected ShopifyCredentialRepository $shopifyCredentialRepository,
        protected ShopifyMappingRepository $shopifyMappingRepository,
        protected ShopifyExportMappingRepository $shopifyExportMappingRepository,
        protected ShopifyMetaFieldRepository $shopifyMetaFieldRepository,
        protected AttributeRepository $attributeRepository,
        protected ShopifyGraphQLDataFormatter $shopifyGraphQLDataFormatter,
        protected ProductValueMapper $productValueMapper,
        protected FileReferenceUploader $fileReferenceUploader,
        protected AssetUrlResolver $assetUrlResolver,
        protected MediaBulkPayloadBuilder $mediaBulkPayloadBuilder,
    ) {}

    /**
     * Build JSONL lines and manifest payload for a batch.
     */
    public function build(array $filters, array $batchRows, JobTrackContract $jobTrack): array
    {
        $this->initialize($filters, $jobTrack);
        $jobTrackId = $jobTrack->id;
        $products = $this->fetchProducts($batchRows);
        $groupedProducts = $this->groupProducts($products);
        $fileReference = $this->collectFileReferenceValues($products);

        $fileReferenceMap = $this->fileReferenceUploader->buildGidMap(
            $fileReference['values'],
            $this->credentialAsArray,
            $jobTrackId,
        );

        foreach ($fileReference['aliases'] as $assetId => $path) {
            if (isset($fileReferenceMap[$path])) {
                $fileReferenceMap[(string) $assetId] = $fileReferenceMap[$path];
            }
        }
        $this->shopifyGraphQLDataFormatter->setFileReferenceMap($fileReferenceMap);

        $lines = [];
        $manifestLines = [];
        $summary = [
            'processed' => count($groupedProducts),
            'created' => 0,
            'skipped' => 0,
        ];
        foreach ($groupedProducts as $group) {
            $payload = $this->buildPayloadForGroup($group, $jobTrackId);
            if (! $payload) {
                $summary['skipped']++;

                continue;
            }

            $summary['created']++;
            $lines[] = json_encode($payload['variables'], JSON_UNESCAPED_SLASHES);
            $manifestLines[] = $payload['manifest'];
        }

        return [
            'lines' => $lines,
            'manifest' => [
                'job_track_id' => $jobTrackId,
                'shop_url' => $this->credential?->shopUrl,
                'credential_id' => $this->credential?->id,
                'credential' => $this->credentialAsArray,
                'channel' => $this->jobChannel,
                'currency' => $this->currency,
                'phase' => BulkOperationService::CORE_PRODUCT_PHASE,
                'follow_up_context' => [
                    'publishing' => true,
                    'media' => true,
                    'translations' => count($this->credential?->storelocaleMapping ?? []) > 1,
                    'publication_ids' => $this->credential?->extras['salesChannel'] ?? '',
                    'location_id' => $this->locationId,
                ],
                'lines' => $manifestLines,
            ],
            'summary' => $summary,
            'credential' => $this->credentialAsArray,
        ];
    }

    /**
     * @return array{values: array<int, array{path: string, content_type: string, url: string}>, aliases: array<string, string>}
     */
    protected function collectFileReferenceValues(array $products): array
    {
        $fileDefs = array_filter(
            array_merge($this->productMetaFieldMapping, $this->variantMetaFieldMapping),
            fn ($def) => ($def['type'] ?? null) === 'file_reference'
        );

        if (empty($fileDefs)) {
            return ['values' => [], 'aliases' => []];
        }

        $values = [];
        $aliases = [];

        foreach ($products as $product) {
            $rawData = $this->getAllAttributeValues($product);

            foreach ($fileDefs as $def) {
                $value = $rawData[$def['code']] ?? null;

                if (empty($value)) {
                    continue;
                }

                $attributeType = $this->attributesAll[$def['code']]?->type ?? null;

                if ($attributeType === 'asset') {
                    foreach ($this->expandAssetFileReferences($value) as $assetId => $entry) {
                        $values[$entry['path']] = $entry;
                        $aliases[(string) $assetId] = $entry['path'];
                    }

                    continue;
                }

                $contentType = json_decode($def['validations'] ?? '[]', true)['content_type'] ?? null;
                if (! $contentType) {
                    $contentType = $attributeType === 'image' ? 'IMAGE' : 'FILE';
                }

                foreach ((array) $value as $single) {
                    $path = (string) $single;
                    $values[$path] = [
                        'path' => $path,
                        'content_type' => $contentType,
                        'url' => $this->assetUrlResolver->resolveMedia($path)['url'] ?? '',
                    ];
                }
            }
        }

        return ['values' => array_values($values), 'aliases' => $aliases];
    }

    /**
     * @return array<int|string, array{path: string, content_type: string, url: string}>
     */
    protected function expandAssetFileReferences(mixed $rawValue): array
    {
        $assetRepository = $this->assetRepository();

        if (! $assetRepository) {
            return [];
        }

        $rawValue = is_array($rawValue) ? implode(',', $rawValue) : (string) $rawValue;
        $ids = array_filter(array_map('trim', explode(',', $rawValue)));

        if (empty($ids)) {
            return [];
        }

        $entries = [];

        foreach ($assetRepository->whereIn('id', $ids)->get() as $asset) {
            $asset = is_array($asset) ? $asset : $asset->toArray();
            $path = $asset['path'] ?? null;
            $mime = $asset['mime_type'] ?? null;

            if (empty($path) || empty($mime)) {
                continue;
            }

            $url = $this->assetUrlResolver->resolveAssetUrl($path);

            if ($url === '') {
                continue;
            }

            $entries[$asset['id']] = [
                'path' => $path,
                'content_type' => $this->assetFileContentType($mime),
                'url' => $url,
            ];
        }

        return $entries;
    }

    protected function assetFileContentType(string $mime): string
    {
        if (in_array($mime, $this->imageMimeTypes, true)) {
            return 'IMAGE';
        }

        return $mime === 'video/mp4' ? 'VIDEO' : 'FILE';
    }

    /**
     * @return array<int, array{namespace: string, key: string, type: string, value: string}>
     */
    protected function buildReferenceMetafields(array $productRow): array
    {
        $defs = array_filter(
            $this->productMetaFieldMapping,
            fn ($d) => in_array($d['type'] ?? '', ['product_reference', 'variant_reference', 'collection_reference'], true)
        );

        if (empty($defs)) {
            return [];
        }

        $values = $productRow['values'] ?? [];
        $metafields = [];
        foreach ($defs as $def) {
            $cfg = json_decode($def['validations'] ?? '[]', true) ?: [];
            if ($def['type'] === 'collection_reference') {
                $gids = $this->resolveCollectionIds($values['categories'] ?? []);
            } else {
                $assocType = $cfg['association_type'] ?? 'related_products';
                $skus = $values['associations'][$assocType] ?? [];
                $field = ($cfg['reference_as'] ?? 'product') === 'variant' ? 'externalId' : 'relatedId';

                $gids = [];
                $mappings = $this->findMappings($skus);
                foreach ($mappings as $sku => $map) {
                    $gid = $map[$field] ?? null;
                    if ($def['type'] === 'variant_reference' && ! $this->resolveVariantGid($gid)) {
                        logger()->warning('Shopify: variant_reference skipped — no variant GID', ['sku' => $sku]);

                        continue;
                    }
                    if ($gid) {
                        $gids[] = $gid;
                    }
                }
                // foreach ($skus as $sku) {
                //     $row = ($this->findMapping($sku) ?? [])[0] ?? null;
                //     $gid = $row[$field] ?? null;

                //     if ($def['type'] === 'variant_reference' && ! $this->resolveVariantGid($gid)) {
                //         logger()->warning('Shopify: variant_reference skipped — no variant GID', ['sku' => $sku]);

                //         continue;
                //     }

                //     if ($gid) {
                //         $gids[] = $gid;
                //     }
                // }
            }

            $gids = array_values(array_unique(array_filter($gids)));
            if (empty($gids)) {
                continue;
            }

            $isList = ! empty($def['listvalue']);
            $nsKey = explode('.', $def['name_space_key']);
            $metafields[] = [
                'namespace' => $nsKey[0],
                'key' => $nsKey[1],
                'type' => $isList ? 'list.'.$def['type'] : $def['type'],
                'value' => $isList ? json_encode($gids, JSON_UNESCAPED_SLASHES) : $gids[0],
            ];
        }

        return $metafields;
    }

    protected function findMappings(array $skus): array
    {
        return $this->shopifyMappingRepository
            ->whereIn('code', $skus)
            ->where('entityType', 'product')
            ->where('apiUrl', $this->credential?->shopUrl)
            ->get()
            ->keyBy('code')
            ->toArray();
    }

    protected function resolveVariantGid(?string $externalId): ?string
    {
        return is_string($externalId) && str_contains($externalId, '/ProductVariant/')
            ? $externalId
            : null;
    }

    /**
     * Initialize context for payload generation.
     */
    protected function initialize(array $filters, JobTrackContract $jobTrack): void
    {
        $this->currency = $filters['currency'] ?? null;
        $this->jobChannel = $filters['channel'] ?? null;
        $this->credential = $this->shopifyCredentialRepository->find($filters['credentials']);

        if (! $this->credential?->active) {
            $jobTrack->state = Export::STATE_FAILED;

            $jobTrack->errors = [trans('shopify::app.shopify.export.errors.invalid-credential')];
            $jobTrack->save();

            throw new InvalidCredential;
        }

        $mappings = $this->shopifyExportMappingRepository->findMany([1, 2]);

        $this->exportMapping = $mappings->first();
        $this->settingMapping = $mappings->last();
        $this->productMetaFieldMapping = $this->shopifyMetaFieldRepository->where('ownerType', 'PRODUCT')->get()->toArray();
        $this->variantMetaFieldMapping = $this->shopifyMetaFieldRepository->where('ownerType', 'PRODUCTVARIANT')->get()->toArray();
        $this->attributesAll = $this->attributeRepository->all()->keyBy('code')->all();
        $this->locationId = $this->credential?->extras['locations'] ?? null;
        $defaultLanguage = array_values(array_filter($this->credential?->storeLocales ?? [], function ($language) {
            return isset($language['defaultlocale']) && $language['defaultlocale'] === true;
        }))[0] ?? null;

        $this->shopifyDefaultLocale = $this->credential?->storelocaleMapping[$defaultLanguage['locale'] ?? ''] ?? null;
        $this->credentialAsArray = $this->credential?->toApiArray() ?? [];
        $this->shopifyGraphQLDataFormatter->setInitialData(
            $this->locationId ?? '',
            $this->currency ?? 'USD',
            $this->settingMapping,
            $this->attributesAll,
            $this->credential?->extras['locationAttributeMappings'] ?? []
        );
    }

    /**
     * Fetch product rows for the current batch.
     */
    protected function fetchProducts(array $batchRows): array
    {
        $skus = $batchRows;
        $tablePrefix = DB::getTablePrefix();

        return DB::table('products')
            ->leftJoin('attribute_families as aft', 'products.attribute_family_id', '=', 'aft.id')
            ->leftJoin('products as parent_products', 'products.parent_id', '=', 'parent_products.id')
            ->leftJoin('product_super_attributes as psa', function ($join) {
                $join->on('parent_products.id', '=', 'psa.product_id')
                    ->orOn('products.id', '=', 'psa.product_id');
            })
            ->leftJoin('attributes as attr', 'psa.attribute_id', '=', 'attr.id')
            ->select(
                'products.id',
                'products.sku',
                'products.status',
                'products.type',
                'products.values',
                'products.attribute_family_id',
                'products.additional',
                'aft.code as attribute_family_code',
                'parent_products.id as parent_id',
                'parent_products.sku as parent_sku',
                'parent_products.type as parent_type',
                'parent_products.status as parent_status',
                'parent_products.values as parent_values',
                'parent_products.attribute_family_id as parent_attribute_family_id',
                DB::raw("COALESCE(GROUP_CONCAT(DISTINCT {$tablePrefix}attr.code ORDER BY {$tablePrefix}attr.code ASC SEPARATOR ','), '') as super_attributes")
            )
            ->where(function ($query) use ($skus) {
                $query->whereIn('products.sku', $skus)
                    ->orWhereIn('parent_products.sku', $skus);
            })
            ->where('products.type', '!=', 'configurable')
            ->groupBy('products.id')
            ->get()
            ->map(function ($product) {
                $parent = $product?->parent_values ? [
                    'id' => $product->parent_id,
                    'sku' => $product->parent_sku,
                    'type' => $product->parent_type,
                    'status' => $product->parent_status,
                    'values' => json_decode($product->parent_values, true),
                    'attribute_family_id' => $product->parent_attribute_family_id,
                    'super_attributes' => $this->hydrateSuperAttributes(explode(',', $product->super_attributes)),
                ] : null;

                return [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'type' => $product->type,
                    'parent' => $parent,
                    'status' => $product->status,
                    'values' => json_decode($product->values, true),
                    'parent_id' => $product->parent_id,
                    'attribute_family_id' => $product->attribute_family_id,
                    'additional' => $product->additional ? json_decode($product->additional, true) : [],
                    'super_attributes' => [],
                ];
            })
            ->all();
    }

    /**
     * Group rows by Shopify product.
     */
    protected function groupProducts(array $products): array
    {
        $grouped = [];

        foreach ($products as $product) {
            $groupSku = $product['parent']['sku'] ?? $product['sku'];

            if (! isset($grouped[$groupSku])) {
                $grouped[$groupSku] = [
                    'product_sku' => $groupSku,
                    'parent' => $product['parent'],
                    'variants' => [],
                ];
            }

            $grouped[$groupSku]['variants'][] = $product;
        }

        return array_values($grouped);
    }

    /**
     * Build a single productSet payload and manifest line.
     */
    protected function buildPayloadForGroup(array $group, int $jobTrackId): ?array
    {
        $parentData = $group['parent'] ?? null;
        $productSku = $group['product_sku'];
        $productMapping = $this->findMapping($productSku);

        $firstVariant = $group['variants'][0] ?? null;

        if (! $firstVariant) {
            return null;
        }
        $categoryCodes = $parentData ? ($parentData['values']['categories'] ?? []) : [];
        $parentMergedFields = $parentData ? $this->getAllAttributeValues($parentData) : [];
        $productMergedFields = $parentData ? $parentMergedFields : $this->getAllAttributeValues($firstVariant);

        $productOptions = $this->buildProductOptions($parentData, $group['variants']);
        $productIdentifierId = $productMapping[0]['relatedId'] ?? $productMapping[0]['externalId'] ?? null;
        $variantGid = $this->resolveVariantGid($productMapping[0]['externalId'] ?? null);
        $formattedProduct = $this->shopifyGraphQLDataFormatter->formatDataForGraphql(
            $this->getAllAttributeValues($firstVariant),
            $this->exportMapping->mapping ?? [],
            $this->shopifyDefaultLocale ?? 'en',
            $parentMergedFields,
            $this->productMetaFieldMapping,
            $this->variantMetaFieldMapping,
            $variantGid !== null
        );

        $referenceMetafields = $this->buildReferenceMetafields($parentData ?? $firstVariant);
        if (! empty($referenceMetafields)) {
            $key = ! empty($formattedProduct['parentMetaFields'])
                ? 'parentMetaFields'
                : 'metafields';
            $formattedProduct[$key] = array_merge(
                $formattedProduct['parentMetaFields'] ?? $formattedProduct['metafields'] ?? [],
                $referenceMetafields
            );
        }
        $productInput = $this->normalizeProductInput($formattedProduct, $productOptions);

        $productInput['handle'] = ($productInput['handle'] ?? null) ?: Str::slug(($productInput['title'] ?? null) ?: $productSku);
        $taxonomyCode = $this->taxonomyAttributeCode();
        $productCategory = $taxonomyCode ? ($productMergedFields[$taxonomyCode] ?? '') : '';

        $productCategoryShort = $productCategory !== '' ? substr($productCategory, strrpos($productCategory, '/') + 1) : null;

        if ($productCategory !== '') {
            $productInput['category'] = $productCategory;
        }

        if (! empty($productInput['metafields'])) {
            $productInput['metafields'] = $this->filterMetafieldsByCategory($productInput['metafields'], $productCategoryShort);
        }

        $variantManifest = [];
        $variants = [];
        $variantFiles = [];
        $mediaByVariant = [];
        foreach ($group['variants'] as $variantRow) {
            $categoryCodes = array_merge($variantRow['values']['categories'] ?? [], $categoryCodes);
            if ($variantRow['sku'] === $productSku) {
                $variantMapping = $productMapping;
                $variantGid = $this->resolveVariantGid($variantMapping[0]['externalId'] ?? null);
                $variantMergedFields = $productMergedFields;
                $formattedVariant = $formattedProduct;
            } else {
                $variantMapping = $this->findMapping($variantRow['sku']);
                $variantGid = $this->resolveVariantGid($variantMapping[0]['externalId'] ?? null);
                $variantMergedFields = $this->getAllAttributeValues($variantRow);
                $formattedVariant = $this->shopifyGraphQLDataFormatter->formatDataForGraphql(
                    $variantMergedFields,
                    $this->exportMapping->mapping ?? [],
                    $this->shopifyDefaultLocale ?? 'en',
                    $parentMergedFields,
                    $this->productMetaFieldMapping,
                    $this->variantMetaFieldMapping,
                    $variantGid !== null
                );

                $mediaByVariant[$productSku] = $productMergedFields;
                $referenceVariantMetafields = $this->buildReferenceMetafields($variantRow);

                if (! empty($referenceVariantMetafields)) {
                    $formattedVariant['metafields'] = array_merge(
                        $formattedVariant['metafields'] ?? [],
                        $referenceVariantMetafields
                    );
                }
            }
            $optionValues = $this->buildVariantOptionValues($parentData, $variantMergedFields);

            $variantFiles[] = ! empty($parentData)
                ? $this->buildVariantFile($variantRow['sku'], $variantMergedFields)
                : null;

            $variants[] = $this->normalizeVariantInput(
                $formattedVariant['variant'] ?? [],
                ! empty($parentData) ? ($formattedVariant['metafields'] ?? []) : [],
                $optionValues,
                $variantGid ?? null,
                ! empty($parentData)
            );

            $variantManifest[] = [
                'sku' => $variantRow['sku'],
                'has_media' => $this->variantHasMedia($variantMergedFields),
            ];

            $mediaByVariant[$variantRow['sku']] = $variantMergedFields;
        }

        $categoryCodes = array_values(array_unique(array_filter($categoryCodes)));
        $productCollections = $this->resolveCollectionIds($categoryCodes);
        if (! empty($productCollections)) {
            $productInput['collections'] = $productCollections;
        }

        $media = $this->resolveProductMedia($mediaByVariant);

        if (! empty($media)) {
            $imageHashes = array_map(fn ($item) => $this->imageContentHash($item['path']), $media);

            if ($this->productMediaChanged($productSku, $imageHashes)) {
                $productInput['files'] = $this->toFileInputs($media);
                foreach ($variants as $index => $variantInput) {
                    if (! empty($variantFiles[$index])) {
                        $variants[$index] = $variantInput + $variantFiles[$index];
                    }
                }
            }
        }

        $productInput['variants'] = $variants;

        return [
            'variables' => [
                'identifier' => $productIdentifierId
                    ? ['id' => $productIdentifierId]
                    : ['handle' => $productInput['handle']],
                'input' => $productInput,
            ],
            'manifest' => [
                'product_sku' => $productSku,
                'product_media' => $media,
                'variant_file' => $variantFiles,
                'product_handle' => $productInput['handle'],
                'variant_skus' => array_column($variantManifest, 'sku'),
                'phase_context' => [
                    'publishing' => ! empty($this->credential?->extras['salesChannel']),
                    'translations' => count($this->credential?->storelocaleMapping ?? []) > 1,
                    'media' => collect($variantManifest)->contains('has_media', true),
                ],
            ],
        ];
    }

    protected function metafieldCategoryConstraints(): array
    {
        if ($this->metafieldCategoryConstraintsMap !== null) {
            return $this->metafieldCategoryConstraintsMap;
        }

        $map = [];

        foreach ($this->productMetaFieldMapping ?? [] as $definition) {
            $categories = $definition['taxonomy_category'] ?? [];

            if (is_string($categories)) {
                $categories = json_decode($categories, true) ?: [];
            }

            $categories = (array) $categories;

            if ($categories !== [] && ! empty($definition['name_space_key'])) {
                $map[$definition['name_space_key']] = array_map(
                    fn ($gid) => substr((string) $gid, strrpos((string) $gid, '/') + 1),
                    $categories
                );
            }
        }

        return $this->metafieldCategoryConstraintsMap = $map;
    }

    /**
     * @param  array<int, array<string, mixed>>  $metafields
     * @return array<int, array<string, mixed>>
     */
    protected function filterMetafieldsByCategory(array $metafields, ?string $categoryShort): array
    {
        $constraints = $this->metafieldCategoryConstraints();
        if ($constraints === []) {
            return $metafields;
        }

        return array_values(array_filter($metafields, function ($metafield) use ($constraints, $categoryShort) {
            $nameSpaceKey = ($metafield['namespace'] ?? '').'.'.($metafield['key'] ?? '');

            if (! isset($constraints[$nameSpaceKey])) {
                return true;
            }

            return $categoryShort !== null && in_array($categoryShort, $constraints[$nameSpaceKey], true);
        }));
    }

    protected function taxonomyAttributeCode(): ?string
    {
        if ($this->taxonomyAttributeCode !== null) {
            return $this->taxonomyAttributeCode ?: null;
        }

        $attribute = collect($this->attributesAll)->first(fn ($attribute) => $attribute->type === 'shopify_taxonomy');

        return $this->taxonomyAttributeCode = ($attribute->code ?? '');
    }

    /**
     * Hydrate super attributes from codes.
     */
    protected function hydrateSuperAttributes(array $codes): array
    {
        $superAttributes = [];

        foreach ($codes as $attributeCode) {
            $attribute = $this->attributesAll[$attributeCode] ?? null;

            if (! $attribute) {
                continue;
            }

            $superAttributes[] = [
                'id' => $attribute->id,
                'code' => $attribute->code,
                'name' => $attribute->name,
                'type' => $attribute->type,
                'translations' => $attribute->translations->toArray(),
            ];
        }

        return $superAttributes;
    }

    /**
     * Build product options from configurable attributes.
     */
    protected function buildProductOptions(?array $parentData, array $variants): array
    {
        if (empty($parentData['super_attributes'])) {
            return [[
                'name' => 'Title',
                'position' => 1,
                'values' => [[
                    'name' => 'Default Title',
                ]],
            ]];
        }

        $options = [];

        foreach ($parentData['super_attributes'] as $index => $attributeMeta) {
            if ($index > 2) {
                continue;
            }

            $optionName = $this->resolveOptionName($attributeMeta);
            $values = [];

            foreach ($variants as $variant) {
                $variantValues = $this->getAllAttributeValues($variant);
                $value = $variantValues[$attributeMeta['code']] ?? null;

                if (! $value || isset($values[$value])) {
                    continue;
                }

                $values[$value] = ['name' => $value];
            }

            if (empty($values)) {
                continue;
            }

            $options[] = [
                'name' => $optionName,
                'position' => $index + 1,
                'values' => array_values($values),
            ];
        }

        return $options;
    }

    /**
     * Build option values for a variant row.
     */
    protected function buildVariantOptionValues(?array $parentData, array $variantMergedFields): array
    {
        if (empty($parentData['super_attributes'])) {
            return [[
                'optionName' => 'Title',
                'name' => 'Default Title',
            ]];
        }

        $optionValues = [];

        foreach ($parentData['super_attributes'] as $attributeMeta) {
            $value = $variantMergedFields[$attributeMeta['code']] ?? null;

            if (! $value) {
                continue;
            }

            $optionValues[] = [
                'optionName' => $this->resolveOptionName($attributeMeta),
                'name' => $value,
            ];
        }

        return $optionValues;
    }

    /**
     * Normalize formatter output into ProductSetInput fields.
     */
    protected function normalizeProductInput(array $formattedProduct, array $productOptions): array
    {
        $productInput = array_filter([
            'title' => $formattedProduct['title'] ?? null,
            'status' => $formattedProduct['status'] ?? null,
            'handle' => $formattedProduct['handle'] ?? null,
            'vendor' => $formattedProduct['vendor'] ?? null,
            'descriptionHtml' => $formattedProduct['descriptionHtml'] ?? null,
            'productType' => $formattedProduct['productType'] ?? null,
            'tags' => $formattedProduct['tags'] ?? null,
            'seo' => $formattedProduct['seo'] ?? null,
            'metafields' => $formattedProduct['parentMetaFields'] ?? $formattedProduct['metafields'] ?? null,
        ], fn ($value) => ! is_null($value) && $value !== []);

        if (! empty($productOptions)) {
            $productInput['productOptions'] = $productOptions;
        }

        return $productInput;
    }

    /**
     * Normalize formatter output into ProductSet variant input fields.
     */
    protected function normalizeVariantInput(
        array $variantPayload,
        array $variantMetafields,
        array $optionValues,
        ?string $variantId,
        bool $includeVariantMetafields
    ): array {

        $inventoryItem = $variantPayload['inventoryItem'] ?? [];

        $variantInput = array_filter([
            'id' => $variantId,
            'price' => $variantPayload['price'] ?? null,
            'compareAtPrice' => $variantPayload['compareAtPrice'] ?? null,
            'barcode' => $variantPayload['barcode'] ?? null,
            'taxable' => $variantPayload['taxable'] ?? null,
            'inventoryPolicy' => $variantPayload['inventoryPolicy'] ?? null,
            'metafields' => $includeVariantMetafields ? ($variantMetafields ?: null) : null,
            'inventoryItem' => empty($inventoryItem) ? null : $inventoryItem,
            'inventoryQuantities' => $variantPayload['inventoryQuantities'] ?? null,
        ], fn ($value) => ! is_null($value) && $value !== []);

        // Shopify's productSet bulk input expects optionValues to be present
        // for variant rows, even when the product has no configurable options.
        $variantInput['optionValues'] = array_values($optionValues);

        // The variant `file` is attached later (in buildPayloadForGroup) so that
        // all variant files are sent together only when the media set changed.
        return $variantInput;
    }

    /**
     * Merge current values according to UnoPim product scopes.
     */
    protected function getAllAttributeValues(array $rowData): array
    {
        return array_merge(
            $this->productValueMapper->getCommonFields($rowData),
            ['status' => $rowData['status'] == 1 ? 'true' : 'false'],
            $this->productValueMapper->getLocaleSpecificFields($rowData, $this->shopifyDefaultLocale ?? ''),
            $this->productValueMapper->getChannelSpecificFields($rowData, $this->jobChannel ?? ''),
            $this->productValueMapper->getChannelLocaleSpecificFields($rowData, $this->jobChannel ?? '', $this->shopifyDefaultLocale ?? '')
        );
    }

    /**
     * Resolve configured option labels.
     */
    protected function resolveOptionName(array $attributeMeta): string
    {
        if (! ($this->settingMapping->mapping['option_name_label'] ?? false)) {
            return $attributeMeta['code'];
        }

        $translation = array_values(array_filter($attributeMeta['translations'], function ($item) {
            return $item['locale'] === $this->shopifyDefaultLocale;
        }))[0] ?? null;

        return $translation['name'] ?? $attributeMeta['name'] ?? $attributeMeta['code'];
    }

    /**
     * Resolve collection ids for a grouped product.
     */
    protected function resolveCollectionIds(array $categoryCodes): array
    {
        if (empty($categoryCodes)) {
            return [];
        }

        return $this->shopifyMappingRepository
            ->whereIn('code', $categoryCodes)
            ->where('entityType', 'category')
            ->where('apiUrl', $this->credential?->shopUrl)
            ->pluck('externalId')
            ->filter()
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Find a Shopify mapping by SKU.
     */
    protected function findMapping(string $sku): ?array
    {
        $mapping = $this->shopifyMappingRepository->where('code', $sku)
            ->where('entityType', 'product')
            ->where('apiUrl', $this->credential?->shopUrl)
            ->get()
            ->toArray();

        return empty($mapping) ? null : $mapping;
    }

    /**
     * Resolve the product's complete media set from the gathered variant fields.
     *
     * productSet replaces the entire media set in one call, so every configured
     * media attribute (across the product and its variant rows) is resolved to a
     * unique image. Each entry carries the storage `path` (used for hashing) and
     * the public `url` + `alt` (used to build the file input).
     *
     * @param  array<string, array>  $mediaByVariant  [sku => mergedFields] gathered during the variant loop
     * @return array<int, array{path: string, url: string, alt: string}>
     */
    protected function resolveProductMedia(array $mediaByVariant): array
    {
        $mediaMapping = $this->exportMapping->mapping['mediaMapping'] ?? [];
        $attributeCodes = array_filter(array_map('trim', explode(',', (string) ($mediaMapping['mediaAttributes'] ?? ''))));

        if (empty($attributeCodes)) {
            return [];
        }

        $mediaType = $mediaMapping['mediaType'] ?? 'image';
        $media = [];
        $seen = [];

        foreach ($mediaByVariant as $sku => $mergedFields) {
            foreach ($attributeCodes as $code) {
                $rawValue = $mergedFields[$code] ?? null;

                if (empty($rawValue)) {
                    continue;
                }

                $slots = $mediaType === 'gallery'
                    ? (is_array($rawValue) ? array_values($rawValue) : [$rawValue])
                    : [is_array($rawValue) ? ($rawValue[0] ?? '') : $rawValue];

                if (($this->attributesAll[$code]->type ?? null) == 'asset') {
                    $assetRepository = $this->assetRepository();

                    if (! $assetRepository) {
                        continue;
                    }

                    $assetsId = explode(',', $rawValue) ?? [];

                    $assets = $assetRepository->whereIn('id', $assetsId)->get();

                    foreach ($assets as $asset) {
                        $asset = is_array($asset) ? $asset : $asset->toArray();
                        $path = $asset['path'] ?? null;
                        $mimeType = $asset['mime_type'] ?? null;

                        if (empty($path)) {
                            continue;
                        }

                        $assetCode = $code.'_'.($asset['id'] ?? '');

                        // Avoid emitting the same asset twice when shared across rows.
                        $dedupeKey = $assetCode.self::CODE_SEPARATOR.$path;

                        if (isset($seen[$dedupeKey])) {
                            continue;
                        }

                        // Video assets cannot be referenced by a public URL the way
                        // images are; Shopify must pull them from a staged upload
                        // target, so the file is streamed up first and the returned
                        // resourceUrl is used as the media source.
                        if ($this->isVideoAsset($asset)) {
                            $resourceUrl = $this->stageVideoUpload($asset, $this->credentialAsArray);

                            if (empty($resourceUrl)) {
                                continue;
                            }

                            $seen[$dedupeKey] = true;

                            $media[] = [
                                'path' => $path,
                                'url' => $resourceUrl,
                                'alt' => $sku.' - '.$assetCode,
                                'contentType' => 'VIDEO',
                            ];

                            continue;
                        }

                        if (! in_array($mimeType, $this->imageMimeTypes, true)) {
                            continue;
                        }

                        $url = $this->resolveAssetUrl($path);

                        if (empty($url)) {
                            continue;
                        }

                        $seen[$dedupeKey] = true;

                        $media[] = [
                            'path' => $path,
                            'url' => $url,
                            'alt' => $sku.' - '.$assetCode,
                            'contentType' => 'IMAGE',
                        ];
                    }

                    continue;
                }

                foreach ($slots as $index => $slotPath) {
                    $resolved = $this->resolveMedia($slotPath);

                    if ($resolved === null) {
                        continue;
                    }

                    $attribute = $mediaType === 'gallery' ? $code.'_'.$index : $code;

                    // Avoid emitting the same media twice when shared across rows.
                    $dedupeKey = $attribute.self::CODE_SEPARATOR.$resolved['path'];

                    if (isset($seen[$dedupeKey])) {
                        continue;
                    }

                    $seen[$dedupeKey] = true;

                    $media[] = [
                        'path' => $resolved['path'],
                        'url' => $resolved['url'],
                        'alt' => $sku.' - '.$attribute,
                        'contentType' => 'IMAGE',
                    ];
                }
            }
        }

        return $media;
    }

    /**
     * Build a publicly fetchable URL for a DAM asset path.
     */
    public function resolveAssetUrl(string $path): string
    {
        if ($this->isAbsoluteUrl($path)) {
            return str_replace(' ', '%20', $path);
        }

        if (! Route::has('admin.dam.file.fetch')) {
            return '';
        }

        return route('admin.dam.file.fetch', ['path' => $path]);
    }

    /**
     * Whether a stored media value is already an absolute http(s) URL.
     */
    public function isAbsoluteUrl(string $value): bool
    {
        return (bool) preg_match('#^https?://#i', $value);
    }

    protected function assetRepository(): ?AssetRepository
    {
        if (! $this->assetRepositoryResolved) {
            $this->assetRepositoryResolved = true;

            if (class_exists(AssetRepository::class)) {
                try {
                    $this->resolvedAssetRepository = app(AssetRepository::class);
                } catch (\Throwable $e) {
                    $this->resolvedAssetRepository = null;
                }
            }
        }

        return $this->resolvedAssetRepository;
    }

    /**
     * Determine whether a DAM asset is a video.
     *
     * Detection prefers the MIME type (e.g. "video/mp4", "video/quicktime") and
     * falls back to the DAM file type classification so assets without a reliable
     * MIME type are still routed through the staged video upload flow.
     *
     * @param  array  $asset  the DAM asset as an array
     */
    protected function isVideoAsset(array $asset): bool
    {
        $mimeType = strtolower((string) ($asset['mime_type'] ?? ''));

        if (str_starts_with($mimeType, 'video/')) {
            return true;
        }

        return strtolower((string) ($asset['file_type'] ?? '')) === 'video';
    }

    /**
     * Upload a DAM video asset to a Shopify staged upload target.
     *
     * Images can be sourced directly from a public URL, but videos must be pulled
     * by Shopify from a staged upload. A stagedUploadsCreate target is requested,
     * the asset is streamed to that target, and the resulting resourceUrl is
     * returned for use as the product media `originalSource`. Returns null on any
     * failure so the caller can skip the video without aborting the export.
     */
    protected function stageVideoUpload(array $asset, array $credential): ?string
    {
        if (empty($asset) || empty($credential) || ! $this->assetRepository()) {
            return null;
        }

        $path = $asset['path'] ?? null;

        if (empty($path)) {
            return null;
        }

        try {
            $response = $this->requestGraphQlApiAction('stagedUploadsCreate', $credential, [
                'input' => [[
                    'filename' => $asset['file_name'] ?? 'video.mp4',
                    'mimeType' => $asset['mime_type'] ?? 'video/mp4',
                    'resource' => strtoupper((string) ($asset['file_type'] ?? 'VIDEO')),
                    'fileSize' => (string) ($asset['file_size'] ?? 0),
                    'httpMethod' => 'POST',
                ]],
            ]);

            $target = $response['body']['data']['stagedUploadsCreate']['stagedTargets'][0] ?? null;

            if (! $target || empty($target['url'])) {
                return null;
            }

            $disk = Directory::getAssetDisk();

            if (! Storage::disk($disk)->exists($path)) {
                return null;
            }

            $multipart = [];

            foreach ($target['parameters'] ?? [] as $param) {
                $multipart[] = [
                    'name' => $param['name'],
                    'contents' => $param['value'],
                ];
            }

            $stream = Storage::disk($disk)->readStream($path);

            if ($stream === false) {
                return null;
            }

            try {
                $multipart[] = [
                    'name' => 'file',
                    'contents' => $stream,
                    'filename' => $asset['file_name'] ?? 'video.mp4',
                    'headers' => [
                        'Content-Type' => $asset['mime_type'] ?? 'video/mp4',
                    ],
                ];

                $upload = Http::asMultipart()->timeout(300)->post($target['url'], $multipart);

                if ($upload->failed()) {
                    return null;
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            return $target['resourceUrl'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Map the resolved media set to productSet [FileSetInput!] entries.
     *
     * Each media item is sent as a create-from-source file — productSet replaces
     * the whole media set and no Shopify media ids are relied upon. Images use
     * their public URL as the source, while videos use the resourceUrl returned by
     * the staged upload (see resolveProductMedia / stageVideoUpload).
     *
     * @param  array<int, array{path: string, url: string, alt: string, contentType?: string}>  $media
     * @return array<int, array>
     */
    protected function toFileInputs(array $media): array
    {
        return array_map(static fn ($item) => [
            'originalSource' => $item['url'],
            'contentType' => $item['contentType'] ?? 'IMAGE',
            'alt' => $item['alt'],
        ], $media);
    }

    /**
     * Hash the raw content of a single image.
     *
     * The actual file bytes are hashed so any change to the image content yields a
     * different hash. When the file cannot be read (e.g. remote/missing), the
     * normalized storage path is hashed as a stable fallback so a changed
     * reference still produces a different hash.
     */
    protected function imageContentHash(string $path): string
    {
        try {
            if (Storage::exists($path)) {
                return hash('sha256', (string) Storage::get($path));
            }
        } catch (\Throwable $e) {
            // Unreadable disk — fall back to the path below.
        }

        return hash('sha256', $path);
    }

    /**
     * Determine whether the product's media set changed since the last export.
     *
     * Compares the current image-content hash set against the hashes previously
     * stored for the parent SKU. Any image added, removed or changed (i.e. the
     * two multisets differ) counts as a change. A product never exported before
     * (no stored hashes) is also treated as changed.
     *
     * @param  array<int, string>  $imageHashes  current per-image content hashes
     */
    protected function productMediaChanged(string $parentSku, array $imageHashes): bool
    {
        $stored = $this->storedImageHashes($parentSku);

        sort($stored);
        sort($imageHashes);

        return $stored !== $imageHashes;
    }

    /**
     * Fetch the previously stored image-content hashes for a parent SKU.
     *
     * @return array<int, string>
     */
    protected function storedImageHashes(string $parentSku): array
    {
        return $this->shopifyMappingRepository
            ->where('entityType', self::MEDIA_HASH_ENTITY_TYPE)
            ->where('relatedSource', $parentSku)
            ->where('apiUrl', $this->credential?->shopUrl)
            ->get()
            ->pluck('code')
            ->map(fn ($code) => (string) $code)
            ->all();
    }

    /**
     * Replace the parent SKU's stored image hashes with the current set.
     *
     * One mapping row is written per image (code => image content hash), keyed by
     * the parent SKU (relatedSource). The previous set is removed first so the
     * stored hashes always mirror the media that was just exported. These hashes
     * plus the parent SKU are the only signal used to detect already-exported
     * media — Shopify media ids are not relied upon.
     *
     * @param  array<int, string>  $imageHashes
     */
    protected function storeMediaHashes(string $parentSku, ?string $parentProductId, array $imageHashes, int $jobTrackId): void
    {
        $this->shopifyMappingRepository->deleteWhere([
            'entityType' => self::MEDIA_HASH_ENTITY_TYPE,
            'relatedSource' => $parentSku,
            'apiUrl' => $this->credential?->shopUrl,
        ]);
        $records = [];
        foreach ($imageHashes as $hash) {
            $records[] = [
                'entityType' => self::MEDIA_HASH_ENTITY_TYPE,
                'code' => $hash,
                'relatedSource' => $parentSku,
                'relatedId' => $parentProductId,
                'jobInstanceId' => $jobTrackId,
                'apiUrl' => $this->credential?->shopUrl,
            ];
        }

        $this->shopifyMappingRepository->insert($records);
    }

    /**
     * Build the single variant-level FileSetInput for a variant row.
     *
     * Shopify variants accept exactly one image. The image is sourced strictly
     * from the variant's own image attribute(s); product-level media is never
     * inherited here. When several image attributes are configured, the first
     * attribute that resolves to an image on the variant wins and only its
     * first image is used, guaranteeing a single image per variant.
     *
     * This only resolves the variant's image into a file candidate. Whether the
     * candidate is actually included is decided once, at the product level: if
     * any image in the whole media set changed, every variant `file` is sent
     * together (see buildPayloadForGroup). Sending only the changed variants'
     * files would make Shopify drop the unchanged variants' image associations.
     *
     * @param  array  $variantMergedFields  the variant's own (non-inherited) values
     * @return array{file: array<string, mixed>}|null
     */
    protected function buildVariantFile(string $sku, array $variantMergedFields): ?array
    {
        $mediaMapping = $this->exportMapping->mapping['mediaMapping'] ?? [];
        $attributeCodes = array_filter(array_map('trim', explode(',', (string) ($mediaMapping['mediaAttributes'] ?? ''))));

        if (empty($attributeCodes)) {
            return null;
        }

        $mediaType = $mediaMapping['mediaType'] ?? 'image';

        foreach ($attributeCodes as $code) {
            $rawValue = $variantMergedFields[$code] ?? null;

            if (empty($rawValue)) {
                continue;
            }

            // Only one image is permitted per variant, so take the first slot
            // regardless of whether the attribute is single or gallery.
            $slotPath = is_array($rawValue) ? ($rawValue[0] ?? '') : $rawValue;

            $resolved = $this->resolveMedia($slotPath);

            if ($resolved === null) {
                continue;
            }

            $attribute = $mediaType === 'gallery' ? $code.'_0' : $code;

            return [
                'file' => [
                    'originalSource' => $resolved['url'],
                    'contentType' => 'IMAGE',
                    'alt' => $sku.' - '.$attribute,
                ],
            ];
        }

        return null;
    }

    /**
     * Normalize a stored media path and resolve it to a public URL.
     *
     * @return array{path: string, url: string}|null
     */
    protected function resolveMedia(mixed $path): ?array
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        $normalizedPath = ltrim($path, '/');
        $fullUrl = Storage::url(str_replace(' ', '%20', $normalizedPath));

        if (empty($fullUrl)) {
            return null;
        }

        return ['path' => $normalizedPath, 'url' => $fullUrl];
    }

    /**
     * Detect whether the variant has media configured in the export mapping.
     */
    protected function variantHasMedia(array $mergedFields): bool
    {
        $mediaMapping = $this->exportMapping->mapping['mediaMapping']['mediaAttributes'] ?? null;

        if (! $mediaMapping) {
            return false;
        }

        foreach (explode(',', $mediaMapping) as $attributeCode) {
            if (! empty($mergedFields[$attributeCode])) {
                return true;
            }
        }

        return false;
    }
}
