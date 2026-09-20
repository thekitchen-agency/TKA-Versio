<?php

declare(strict_types=1);

namespace thekitchenagency\translations\controllers;

use Craft;
use craft\web\Controller;
use craft\helpers\UrlHelper;
use thekitchenagency\translations\Plugin;
use thekitchenagency\translations\web\assets\cp\CpAsset;
use yii\web\Response;

/**
 * Controller for translation message management in the Craft CP.
 */
class MessagesController extends Controller
{
    /**
     * Render the main Translations CP page.
     */
    public function actionIndex(?string $category = null): Response
    {
        $this->getView()->registerAssetBundle(CpAsset::class);

        $settings = Plugin::getInstance()->getSettings();
        $categories = $settings->getCategories();
        $activeCategory = $category !== null && in_array($category, $categories, true) ? $category : $categories[0];

        $siteLocales = Craft::$app->getI18n()->getSiteLocales();
        $localesData = array_map(function($loc) {
            return [
                'id' => $loc->id,
                'name' => $loc->getDisplayName(Craft::$app->language),
                'nativeName' => $loc->getDisplayName(),
            ];
        }, $siteLocales);

        return $this->renderTemplate('tka-translations/index', [
            'pluginName' => $settings->pluginName,
            'title' => Craft::t('tka-translations', 'Translations'),
            'categories' => $categories,
            'activeCategory' => $activeCategory,
            'locales' => $localesData,
            'hasAi' => $settings->hasAiTranslationConfigured(),
            'defaultAiProvider' => $settings->defaultAiProvider,
            'selectedSubnavItem' => 'messages',
        ]);
    }

    /**
     * Get paginated translations (AJAX endpoint for Alpine.js).
     */
    public function actionGetTranslations(): Response
    {
        $this->requireAcceptsJson();

        $category = (string)$this->request->getParam('category', 'site');
        $params = [
            'page' => (int)$this->request->getParam('page', 1),
            'perPage' => (int)$this->request->getParam('perPage', 50),
            'search' => (string)$this->request->getParam('search', ''),
            'status' => (string)$this->request->getParam('status', 'all'),
            'sort' => (string)$this->request->getParam('sort', 'message'),
            'dir' => (string)$this->request->getParam('dir', 'asc'),
        ];

        $data = Plugin::getInstance()->messages->getPaginated($category, $params);

        return $this->asJson([
            'success' => true,
            'category' => $category,
            ...$data,
        ]);
    }

    /**
     * Add a new single translation key.
     */
    public function actionAdd(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_TRANSLATIONS);

        $message = (string)$this->request->getRequiredBodyParam('message');
        $category = (string)$this->request->getRequiredBodyParam('category');
        $translations = (array)$this->request->getBodyParam('translations', []);

        $sourceId = Plugin::getInstance()->messages->addMessage($message, $category, $translations);

        if ($sourceId === null) {
            return $this->asJson([
                'success' => false,
                'message' => Craft::t('tka-translations', 'Could not add translation (it may already exist).'),
            ]);
        }

        return $this->asJson([
            'success' => true,
            'id' => $sourceId,
            'message' => Craft::t('tka-translations', 'Translation key added successfully.'),
        ]);
    }

    /**
     * Batch save multiple modified translation rows.
     */
    public function actionSaveBatch(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_TRANSLATIONS);

        $translations = (array)$this->request->getRequiredBodyParam('translations');
        $category = (string)$this->request->getBodyParam('category', 'site');

        $success = Plugin::getInstance()->messages->saveBatch($translations, $category);

        return $this->asJson([
            'success' => $success,
            'message' => $success
                ? Craft::t('tka-translations', 'Translations saved successfully.')
                : Craft::t('tka-translations', 'Failed to save translations.'),
        ]);
    }

    /**
     * Delete multiple selected translation keys.
     */
    public function actionDeleteBatch(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_TRANSLATIONS);

        $sourceIds = (array)$this->request->getRequiredBodyParam('sourceIds');
        $category = (string)$this->request->getBodyParam('category', 'site');

        $success = Plugin::getInstance()->messages->deleteBatch($sourceIds, $category);

        return $this->asJson([
            'success' => $success,
            'message' => $success
                ? Craft::t('tka-translations', 'Translations deleted successfully.')
                : Craft::t('tka-translations', 'Failed to delete translations.'),
        ]);
    }

    /**
     * Translate a single text via AI (DeepL / OpenAI / Gemini).
     */
    public function actionAutoTranslate(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_TRANSLATIONS);

        $text = (string)$this->request->getRequiredBodyParam('text');
        $targetLocale = (string)$this->request->getRequiredBodyParam('targetLocale');
        $sourceLocale = (string)$this->request->getBodyParam('sourceLocale', Plugin::getInstance()->getSettings()->getSourceLanguage());
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
}
