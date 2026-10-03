<?php

declare(strict_types=1);

namespace thekitchenagency\translations\controllers;

use Craft;
use craft\web\Controller;
use thekitchenagency\translations\Plugin;
use thekitchenagency\translations\web\assets\cp\EntryTranslatorAsset;
use yii\web\Response;
use Exception;

/**
 * Controller for side-by-side entry translations.
 */
class EntriesController extends Controller
{
    /**
     * Render the Entry Translation workspace.
     */
    public function actionIndex(): Response
    {
        $this->getView()->registerAssetBundle(EntryTranslatorAsset::class);

        $settings = Plugin::getInstance()->getSettings();
        $sections = Plugin::getInstance()->entryTranslation->getTranslatableSections();

        $allSites = Craft::$app->getSites()->getAllSites();
        $primarySite = Craft::$app->getSites()->getPrimarySite();

        $sourceSiteId = $primarySite->id;
        $targetSiteId = null;
        foreach ($allSites as $s) {
            if ($s->id !== $sourceSiteId) {
                $targetSiteId = $s->id;
                break;
            }
        }
        $targetSiteId = $targetSiteId ?: $sourceSiteId;

        $sitesData = array_map(function($s) {
            return [
                'id' => (int)$s->id,
                'name' => $s->name,
                'handle' => $s->handle,
                'language' => $s->language,
            ];
        }, $allSites);

        return $this->renderTemplate('tka-translations/entries/index', [
            'pluginName' => $settings->pluginName,
            'title' => Craft::t('tka-translations', 'Entry Translations'),
            'sections' => $sections,
            'sites' => $sitesData,
            'defaultSourceSiteId' => $sourceSiteId,
            'defaultTargetSiteId' => $targetSiteId,
            'hasAi' => $settings->hasAiTranslationConfigured(),
            'defaultAiProvider' => $settings->defaultAiProvider,
            'selectedSubnavItem' => 'entries',
        ]);
    }

    /**
     * Get paginated entries for a section with translation status.
     */
    public function actionGetEntries(): Response
    {
        $this->requireAcceptsJson();

        $sectionId = (int)$this->request->getParam('sectionId');
        $sourceSiteId = (int)$this->request->getParam('sourceSiteId', Craft::$app->getSites()->getPrimarySite()->id);
        $targetSiteId = (int)$this->request->getParam('targetSiteId', $sourceSiteId);

        $params = [
            'page' => (int)$this->request->getParam('page', 1),
            'perPage' => (int)$this->request->getParam('perPage', 25),
            'search' => (string)$this->request->getParam('search', ''),
            'status' => (string)$this->request->getParam('status', 'all'),
        ];

        if (!$sectionId) {
            return $this->asJson([
                'success' => false,
                'message' => 'Section ID is required.',
            ]);
        }

        $data = Plugin::getInstance()->entryTranslation->getEntries($sectionId, $sourceSiteId, $targetSiteId, $params);

        return $this->asJson([
            'success' => true,
            ...$data,
        ]);
    }

    /**
     * Get field comparison tree for a specific entry.
     */
    public function actionGetEntryData(): Response
    {
        $this->requireAcceptsJson();

        $entryId = (int)$this->request->getParam('entryId');
        $sourceSiteId = (int)$this->request->getParam('sourceSiteId', Craft::$app->getSites()->getPrimarySite()->id);
        $targetSiteId = (int)$this->request->getParam('targetSiteId');

        if (!$entryId || !$targetSiteId) {
            return $this->asJson([
                'success' => false,
                'message' => 'Entry ID and Target Site ID are required.',
            ]);
        }

        $data = Plugin::getInstance()->entryTranslation->getEntryComparisonData($entryId, $sourceSiteId, $targetSiteId);

        if (!$data) {
            return $this->asJson([
                'success' => false,
                'message' => Craft::t('tka-translations', 'Entry not found.'),
            ]);
        }

        return $this->asJson([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Save target entry translation.
     */
    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_TRANSLATIONS);

        $entryId = (int)$this->request->getRequiredBodyParam('entryId');
        $targetSiteId = (int)$this->request->getRequiredBodyParam('targetSiteId');
        $data = (array)$this->request->getBodyParam('data', []);

        try {
            $success = Plugin::getInstance()->entryTranslation->saveTargetEntry($entryId, $targetSiteId, $data);
            return $this->asJson([
                'success' => $success,
                'message' => $success
                    ? Craft::t('tka-translations', 'Target entry saved successfully.')
                    : Craft::t('tka-translations', 'Failed to save target entry.'),
            ]);
        } catch (\Throwable $e) {
            Craft::error("Failed to save target entry: " . $e->getMessage(), __METHOD__);
            return $this->asJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * AI translate a single field text or HTML content.
     */
    public function actionAiTranslateField(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_TRANSLATIONS);

        $text = (string)$this->request->getRequiredBodyParam('text');
        $targetLocale = (string)$this->request->getRequiredBodyParam('targetLocale');
        $sourceLocale = (string)$this->request->getBodyParam('sourceLocale', 'de');
        $provider = $this->request->getBodyParam('provider');

        $translation = Plugin::getInstance()->ai->translate($text, $targetLocale, $sourceLocale, $provider);

        if ($translation === null) {
            $lastError = Plugin::getInstance()->ai->getLastError();
            return $this->asJson([
                'success' => false,
                'message' => $lastError ?: Craft::t('tka-translations', 'AI translation failed. Check your API keys in Settings.'),
            ]);
        }

        return $this->asJson([
            'success' => true,
            'translation' => $translation,
        ]);
    }

    /**
     * Batch AI translate all fields of an entry.
     */
    public function actionAiTranslateEntry(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_TRANSLATIONS);

        $entryId = (int)$this->request->getRequiredBodyParam('entryId');
        $sourceSiteId = (int)$this->request->getRequiredBodyParam('sourceSiteId');
        $targetSiteId = (int)$this->request->getRequiredBodyParam('targetSiteId');
        $provider = $this->request->getBodyParam('provider');

        try {
            $translatedData = Plugin::getInstance()->entryTranslation->aiTranslateEntryFields($entryId, $sourceSiteId, $targetSiteId, $provider);
            if (!$translatedData) {
                return $this->asJson([
                    'success' => false,
                    'message' => Craft::t('tka-translations', 'Could not translate entry.'),
                ]);
            }

            return $this->asJson([
                'success' => true,
                'data' => $translatedData,
            ]);
        } catch (\Throwable $e) {
            return $this->asJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
