<?php

declare(strict_types=1);

namespace thekitchenagency\translations\services;

use Craft;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\fields\Table;
use craft\helpers\ElementHelper;
use craft\helpers\StringHelper;
use craft\models\Section;
use craft\models\Site;
use thekitchenagency\translations\Plugin;
use yii\base\Component;
use Exception;

/**
 * Service for side-by-side multi-site entry translations.
 */
class EntryTranslationService extends Component
{
    /**
     * Get all sections eligible for side-by-side translation.
     *
     * @return array<int, array{id: int, name: string, handle: string, type: string, sites: array}>
     */
    public function getTranslatableSections(): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $configuredHandles = $settings->translatableSections;

        $sections = Craft::$app->getEntries()->getAllSections();
        $allSites = Craft::$app->getSites()->getAllSites();
        $siteMap = [];
        foreach ($allSites as $s) {
            $siteMap[$s->id] = [
                'id' => $s->id,
                'name' => $s->name,
                'handle' => $s->handle,
                'language' => $s->language,
            ];
        }

        $result = [];
        foreach ($sections as $section) {
            // Check if section is enabled for more than 1 site
            $siteSettings = $section->getSiteSettings();
            if (count($siteSettings) < 2) {
                continue;
            }

            if (!empty($configuredHandles) && !in_array($section->handle, $configuredHandles, true)) {
                continue;
            }

            $sectionSites = [];
            foreach ($siteSettings as $ss) {
                if (isset($siteMap[$ss->siteId])) {
                    $sectionSites[] = $siteMap[$ss->siteId];
                }
            }

            $result[] = [
                'id' => (int)$section->id,
                'name' => $section->name,
                'handle' => $section->handle,
                'type' => $section->type,
                'sites' => $sectionSites,
            ];
        }

        return $result;
    }

    /**
     * Get paginated list of entries for a section with translation status.
     *
     * @return array{items: array, total: int, page: int, perPage: int, totalPages: int}
     */
    public function getEntries(int $sectionId, int $sourceSiteId, int $targetSiteId, array $params = []): array
    {
        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = max(10, min(100, (int)($params['perPage'] ?? 25)));
        $search = trim((string)($params['search'] ?? ''));
        $status = (string)($params['status'] ?? 'all'); // 'all', 'translated', 'missing'

        $query = Entry::find()
            ->sectionId($sectionId)
            ->siteId($sourceSiteId)
            ->status(null);

        if ($search !== '') {
            $query->search($search);
        }

        $total = (int)$query->count();
        $totalPages = max(1, (int)ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;

        $sourceEntries = $query
            ->offset($offset)
            ->limit($perPage)
            ->all();

        $entryIds = array_map(fn(Entry $e) => $e->id, $sourceEntries);

        // Fetch corresponding target entries in bulk
        $targetEntriesMap = [];
        if (!empty($entryIds)) {
            $targetEntries = Entry::find()
                ->id($entryIds)
                ->siteId($targetSiteId)
                ->status(null)
                ->all();

            foreach ($targetEntries as $te) {
                $targetEntriesMap[$te->id] = $te;
            }
        }

        $items = [];
        foreach ($sourceEntries as $se) {
            /** @var Entry|null $te */
            $te = $targetEntriesMap[$se->id] ?? null;

            $progress = $this->calculateEntryTranslationProgress($se, $te, $sourceSiteId, $targetSiteId);

            if ($status === 'untranslated' && $progress['status'] !== 'untranslated') {
                continue;
            }
            if ($status === 'missing' && $progress['status'] !== 'untranslated') {
                continue;
            }
            if ($status === 'partial' && $progress['status'] !== 'partial') {
                continue;
            }
            if ($status === 'translated' && $progress['status'] !== 'translated') {
                continue;
            }

            $hasTarget = $te !== null;
            $isTargetEnabled = $hasTarget && $te->enabled && $te->getEnabledForSite($targetSiteId);
            $targetTitle = $te ? (string)$te->title : '';

            $items[] = [
                'id' => (int)$se->id,
                'title' => (string)$se->title,
                'slug' => (string)$se->slug,
                'sourceEnabled' => (bool)$se->enabled,
                'postDate' => $se->postDate ? $se->postDate->format('Y-m-d H:i') : null,
                'targetTitle' => $targetTitle,
                'targetSlug' => $te ? (string)$te->slug : '',
                'targetEnabled' => $isTargetEnabled,
                'hasTargetVersion' => $hasTarget,
                'totalFields' => $progress['total'],
                'translatedFields' => $progress['translated'],
                'progressPercent' => $progress['percent'],
                'status' => $progress['status'],
                'isTranslated' => $progress['status'] === 'translated',
            ];
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
        ];
    }

    /**
     * Calculate translation progress by comparing source and target content fields and matrix blocks.
     *
     * @return array{total: int, translated: int, percent: int, status: string}
     */
    public function calculateEntryTranslationProgress(Entry $sourceEntry, ?Entry $targetEntry, int $sourceSiteId, int $targetSiteId): array
    {
        if (!$targetEntry) {
            $comparison = $this->getEntryComparisonData($sourceEntry->id, $sourceSiteId, $targetSiteId);
            $total = 0;
            if ($comparison) {
                foreach ($comparison['fields'] as $f) {
                    if ($f['handle'] !== 'slug' && trim((string)$f['sourceValue']) !== '') {
                        $total++;
                    }
                }
                foreach ($comparison['matrixFields'] as $m) {
                    foreach ($m['blocks'] as $b) {
                        foreach ($b['fields'] as $bf) {
                            if (trim(strip_tags((string)$bf['sourceValue'])) !== '') {
                                $total++;
                            }
                        }
                    }
                }
            }
            return [
                'total' => max(1, $total),
                'translated' => 0,
                'percent' => 0,
                'status' => 'untranslated',
            ];
        }

        $comparison = $this->getEntryComparisonData($sourceEntry->id, $sourceSiteId, $targetSiteId);
        if (!$comparison) {
            return [
                'total' => 1,
                'translated' => 0,
                'percent' => 0,
                'status' => 'untranslated',
            ];
        }

        $totalFields = 0;
        $translatedFields = 0;

        // 1. Standard Fields (Title, custom text/HTML fields)
        foreach ($comparison['fields'] as $field) {
            if ($field['handle'] === 'slug') {
                continue;
            }
            $src = trim(strip_tags((string)$field['sourceValue']));
            $tgt = trim(strip_tags((string)$field['targetValue']));

            if ($src !== '') {
                $totalFields++;
                if ($tgt !== '' && $tgt !== $src) {
                    $translatedFields++;
                }
            }
        }

        // 2. Matrix Blocks
        foreach ($comparison['matrixFields'] as $mField) {
            foreach ($mField['blocks'] as $block) {
                foreach ($block['fields'] as $bField) {
                    $bSrc = trim(strip_tags((string)$bField['sourceValue']));
                    $bTgt = trim(strip_tags((string)$bField['targetValue']));

                    if ($bSrc !== '') {
                        $totalFields++;
                        if ($bTgt !== '' && $bTgt !== $bSrc) {
                            $translatedFields++;
                        }
                    }
                }
            }
        }

        $totalFields = max(1, $totalFields);
        $percent = (int)round(($translatedFields / $totalFields) * 100);

        $status = 'untranslated';
        if ($translatedFields === $totalFields) {
            $status = 'translated';
        } elseif ($translatedFields > 0) {
            $status = 'partial';
        }

        return [
            'total' => $totalFields,
            'translated' => $translatedFields,
            'percent' => $percent,
            'status' => $status,
        ];
    }

    /**
     * Get detailed field comparison data between source and target site versions of an entry.
     */
    public function getEntryComparisonData(int $entryId, int $sourceSiteId, int $targetSiteId): ?array
    {
        /** @var Entry|null $sourceEntry */
        $sourceEntry = Entry::find()
            ->id($entryId)
            ->siteId($sourceSiteId)
            ->status(null)
            ->one();

        if (!$sourceEntry) {
            return null;
        }

        /** @var Entry|null $targetEntry */
        $targetEntry = Entry::find()
            ->id($entryId)
            ->siteId($targetSiteId)
            ->status(null)
            ->one();

        $sourceSite = Craft::$app->getSites()->getSiteById($sourceSiteId);
        $targetSite = Craft::$app->getSites()->getSiteById($targetSiteId);

        $entryType = $sourceEntry->getType();
        $fieldLayout = $entryType->getFieldLayout();

        // Extract Standard & Custom Fields
        $fieldsData = [];

        // 1. Title
        $fieldsData[] = [
            'handle' => 'title',
            'name' => Craft::t('app', 'Title'),
            'type' => 'text',
            'isCore' => true,
            'sourceValue' => (string)$sourceEntry->title,
            'targetValue' => $targetEntry ? (string)$targetEntry->title : '',
            'translatable' => true,
        ];

        // 2. Slug
        $fieldsData[] = [
            'handle' => 'slug',
            'name' => Craft::t('app', 'Slug'),
            'type' => 'slug',
            'isCore' => true,
            'sourceValue' => (string)$sourceEntry->slug,
            'targetValue' => $targetEntry ? (string)$targetEntry->slug : '',
            'translatable' => true,
        ];

        // 3. Custom Fields
        $matrixFieldsData = [];

        foreach ($fieldLayout->getCustomFields() as $field) {
            if ($field instanceof Matrix) {
                // Handle Matrix / Page Builder
                $matrixData = $this->extractMatrixComparison($field, $sourceEntry, $targetEntry, $sourceSiteId, $targetSiteId);
                if (!empty($matrixData)) {
                    $matrixFieldsData[] = $matrixData;
                }
                continue;
            }

            $fieldInfo = $this->extractFieldComparison($field, $sourceEntry, $targetEntry);
            if ($fieldInfo !== null) {
                $fieldsData[] = $fieldInfo;
            }
        }

        return [
            'entry' => [
                'id' => (int)$sourceEntry->id,
                'sectionId' => (int)$sourceEntry->sectionId,
                'sectionName' => $sourceEntry->getSection()->name,
                'typeId' => (int)$entryType->id,
                'typeName' => $entryType->name,
                'cpEditUrl' => $sourceEntry->getCpEditUrl(),
            ],
            'sourceSite' => [
                'id' => $sourceSite->id,
                'name' => $sourceSite->name,
                'language' => $sourceSite->language,
            ],
            'targetSite' => [
                'id' => $targetSite->id,
                'name' => $targetSite->name,
                'language' => $targetSite->language,
            ],
            'fields' => $fieldsData,
            'matrixFields' => $matrixFieldsData,
        ];
    }

    /**
     * Extract comparison for a single custom field.
     */
    protected function extractFieldComparison(FieldInterface $field, Entry $sourceEntry, ?Entry $targetEntry): ?array
    {
        $handle = $field->handle;
        $class = get_class($field);

        $sourceVal = $sourceEntry->getFieldValue($handle);
        $targetVal = $targetEntry ? $targetEntry->getFieldValue($handle) : null;

        // Rich text (CKEditor / Redactor)
        if (str_contains(strtolower($class), 'ckeditor') || str_contains(strtolower($class), 'redactor') || str_contains(strtolower($class), 'richtext')) {
            return [
                'handle' => $handle,
                'name' => $field->name,
                'type' => 'html',
                'isCore' => false,
                'sourceValue' => (string)($sourceVal ?? ''),
                'targetValue' => (string)($targetVal ?? ''),
                'translatable' => $field->translationMethod !== \craft\base\Field::TRANSLATION_METHOD_NONE,
            ];
        }

        // Plain text & Textarea
        if ($field instanceof PlainText) {
            $isMultiline = (bool)($field->multiline ?? false);
            return [
                'handle' => $handle,
                'name' => $field->name,
                'type' => $isMultiline ? 'textarea' : 'text',
                'isCore' => false,
                'sourceValue' => (string)($sourceVal ?? ''),
                'targetValue' => (string)($targetVal ?? ''),
                'translatable' => $field->translationMethod !== \craft\base\Field::TRANSLATION_METHOD_NONE,
            ];
        }

        // If it's a simple scalar string
        if (is_string($sourceVal)) {
            return [
                'handle' => $handle,
                'name' => $field->name,
                'type' => strlen($sourceVal) > 80 || str_contains($sourceVal, "\n") ? 'textarea' : 'text',
                'isCore' => false,
                'sourceValue' => (string)$sourceVal,
                'targetValue' => (string)($targetVal ?? ''),
                'translatable' => $field->translationMethod !== \craft\base\Field::TRANSLATION_METHOD_NONE,
            ];
        }

        return null;
    }

    /**
     * Extract Matrix / Page Builder blocks comparison (Craft 5 native nested entries).
     */
    protected function extractMatrixComparison(Matrix $matrixField, Entry $sourceEntry, ?Entry $targetEntry, int $sourceSiteId, int $targetSiteId): array
    {
        $handle = $matrixField->handle;

        $sourceBlocksQuery = $sourceEntry->getFieldValue($handle);
        $sourceBlocks = $sourceBlocksQuery ? $sourceBlocksQuery->all() : [];

        $targetBlocksQuery = $targetEntry ? $targetEntry->getFieldValue($handle) : null;
        $targetBlocks = $targetBlocksQuery ? $targetBlocksQuery->all() : [];

        $targetBlocksMap = [];
        $targetBlocksById = [];
        foreach ($targetBlocks as $idx => $tb) {
            $targetBlocksMap[$idx] = $tb;
            $targetBlocksById[$tb->id] = $tb;
            if ($tb->canonicalId) {
                $targetBlocksById[$tb->canonicalId] = $tb;
            }
        }

        $blocks = [];
        foreach ($sourceBlocks as $index => $sourceBlock) {
            /** @var Entry $sourceBlock */
            $targetBlock = $targetBlocksById[$sourceBlock->id] ?? ($targetBlocksMap[$index] ?? null);

            $blockType = $sourceBlock->getType();
            $blockLayout = $blockType->getFieldLayout();

            $blockFields = [];
            foreach ($blockLayout->getCustomFields() as $bField) {
                $bInfo = $this->extractFieldComparison($bField, $sourceBlock, $targetBlock);
                if ($bInfo !== null) {
                    $blockFields[] = $bInfo;
                }
            }

            if (!empty($blockFields)) {
                $blocks[] = [
                    'index' => $index,
                    'sourceId' => (int)$sourceBlock->id,
                    'targetId' => $targetBlock ? (int)$targetBlock->id : null,
                    'typeHandle' => $blockType->handle,
                    'typeName' => $blockType->name,
                    'fields' => $blockFields,
                ];
            }
        }

        return [
            'handle' => $handle,
            'name' => $matrixField->name,
            'blocks' => $blocks,
        ];
    }

    /**
     * Save target entry translations.
     */
    public function saveTargetEntry(int $entryId, int $targetSiteId, array $data): bool
    {
        /** @var Entry|null $targetEntry */
        $targetEntry = Entry::find()
            ->id($entryId)
            ->siteId($targetSiteId)
            ->status(null)
            ->one();

        if (!$targetEntry) {
            /** @var Entry|null $sourceEntry */
            $sourceEntry = Entry::find()->id($entryId)->status(null)->one();
            if (!$sourceEntry) {
                throw new Exception("Source entry [{$entryId}] not found.");
            }

            $targetEntry = Craft::$app->getEntries()->getEntryById($entryId, $targetSiteId);
            if (!$targetEntry) {
                $targetEntry = clone $sourceEntry;
                $targetEntry->siteId = $targetSiteId;
            }
        }

        // Set Title
        if (isset($data['title']) && trim((string)$data['title']) !== '') {
            $targetEntry->title = trim((string)$data['title']);
        }

        // Set Slug
        if (isset($data['slug']) && trim((string)$data['slug']) !== '') {
            $targetEntry->slug = ElementHelper::generateSlug(trim((string)$data['slug']));
        } elseif (!empty($targetEntry->title) && empty($targetEntry->slug)) {
            $targetEntry->slug = ElementHelper::generateSlug($targetEntry->title);
        }

        // Set Custom Fields
        if (isset($data['fields']) && is_array($data['fields'])) {
            foreach ($data['fields'] as $fieldHandle => $fieldValue) {
                $targetEntry->setFieldValue($fieldHandle, $fieldValue);
            }
        }

        // Ensure target entry is enabled for the target site
        $targetEntry->setEnabledForSite(true);

        // Save target entry first
        if (!Craft::$app->getElements()->saveElement($targetEntry)) {
            $errors = $targetEntry->getFirstErrors();
            throw new Exception(Craft::t('tka-translations', 'Failed to save target entry: {errors}', [
                'errors' => implode(', ', $errors),
            ]));
        }

        // Set and save Matrix Blocks Fields
        if (isset($data['matrix']) && is_array($data['matrix'])) {
            foreach ($data['matrix'] as $matrixHandle => $blocksData) {
                $targetBlocksQuery = $targetEntry->getFieldValue($matrixHandle);
                if ($targetBlocksQuery) {
                    $targetBlocks = $targetBlocksQuery->all();
                    $targetBlocksById = [];
                    foreach ($targetBlocks as $idx => $tb) {
                        $targetBlocksById[$tb->id] = $tb;
                        if ($tb->canonicalId) {
                            $targetBlocksById[$tb->canonicalId] = $tb;
                        }
                    }

                    foreach ($blocksData as $blockIndex => $blockValues) {
                        /** @var Entry|null $targetBlock */
                        $targetBlock = $targetBlocksById[(int)$blockIndex] ?? ($targetBlocks[$blockIndex] ?? null);
                        if ($targetBlock) {
                            foreach ($blockValues as $bHandle => $bVal) {
                                $targetBlock->setFieldValue($bHandle, $bVal);
                            }
                            $targetBlock->setEnabledForSite(true);
                            if (!Craft::$app->getElements()->saveElement($targetBlock)) {
                                $errors = $targetBlock->getFirstErrors();
                                throw new Exception(Craft::t('tka-translations', 'Failed to save block #{index} ({type}): {errors}', [
                                    'index' => is_numeric($blockIndex) ? (int)$blockIndex + 1 : $blockIndex,
                                    'type' => $targetBlock->getType()->name,
                                    'errors' => implode(', ', $errors),
                                ]));
                            }
                        }
                    }
                }
            }
        }

        return true;
    }

    /**
     * AI Translate all text fields in an entry from source site to target site.
     *
     * @return array{title: string, slug: string, fields: array, matrix: array}
     */
    public function aiTranslateEntryFields(int $entryId, int $sourceSiteId, int $targetSiteId, ?string $provider = null): ?array
    {
        $comparison = $this->getEntryComparisonData($entryId, $sourceSiteId, $targetSiteId);
        if (!$comparison) {
            return null;
        }

        $sourceLang = $comparison['sourceSite']['language'];
        $targetLang = $comparison['targetSite']['language'];
        $ai = Plugin::getInstance()->ai;

        $translatedData = [
            'title' => '',
            'slug' => '',
            'fields' => [],
            'matrix' => [],
        ];

        // 1. Translate Standard Fields
        foreach ($comparison['fields'] as $field) {
            $srcVal = trim((string)$field['sourceValue']);
            if ($srcVal === '') {
                continue;
            }

            if ($field['handle'] === 'title') {
                $translatedTitle = $ai->translate($srcVal, $targetLang, $sourceLang, $provider);
                if ($translatedTitle) {
                    $translatedData['title'] = $translatedTitle;
                    $translatedData['slug'] = ElementHelper::generateSlug($translatedTitle);
                }
            } elseif ($field['handle'] === 'slug') {
                // Slug is derived from title
            } else {
                $translatedVal = $ai->translate($srcVal, $targetLang, $sourceLang, $provider);
                if ($translatedVal !== null) {
                    $translatedData['fields'][$field['handle']] = $translatedVal;
                }
            }
        }

        // 2. Translate Matrix Fields
        foreach ($comparison['matrixFields'] as $mField) {
            $mHandle = $mField['handle'];
            $translatedData['matrix'][$mHandle] = [];

            foreach ($mField['blocks'] as $block) {
                $bIdx = $block['index'];
                $translatedData['matrix'][$mHandle][$bIdx] = [];

                foreach ($block['fields'] as $bField) {
                    $bSrcVal = trim((string)$bField['sourceValue']);
                    if ($bSrcVal === '') {
                        continue;
                    }

                    $translatedBVal = $ai->translate($bSrcVal, $targetLang, $sourceLang, $provider);
                    if ($translatedBVal !== null) {
                        $translatedData['matrix'][$mHandle][$bIdx][$bField['handle']] = $translatedBVal;
                    }
                }
            }
        }

        return $translatedData;
    }
}
