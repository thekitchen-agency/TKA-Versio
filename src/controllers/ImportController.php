<?php

declare(strict_types=1);

namespace thekitchenagency\translations\controllers;

use Craft;
use craft\web\Controller;
use craft\web\UploadedFile;
use thekitchenagency\translations\jobs\ImportTranslationsJob;
use thekitchenagency\translations\Plugin;
use thekitchenagency\translations\web\assets\cp\CpAsset;
use yii\web\Response;

/**
 * Controller for importing translations.
 */
class ImportController extends Controller
{
    public function actionIndex(): Response
    {
        $this->getView()->registerAssetBundle(CpAsset::class);
        $settings = Plugin::getInstance()->getSettings();

        return $this->renderTemplate('tka-translations/import', [
            'pluginName' => $settings->pluginName,
            'title' => Craft::t('tka-translations', 'Import Translations'),
            'categories' => $settings->getCategories(),
            'selectedSubnavItem' => 'import',
        ]);
    }

    /**
     * Import existing static PHP translation files into DB.
     */
    public function actionImportPhp(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_TRANSLATIONS);

        Craft::$app->getQueue()->push(new ImportTranslationsJob());

        Craft::$app->getSession()->setNotice(Craft::t('tka-translations', 'PHP translation import added to queue.'));
        return $this->redirect('tka-translations/import');
    }

    /**
     * Import uploaded CSV file into DB.
     */
    public function actionImportCsv(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_TRANSLATIONS);

        $category = (string)$this->request->getRequiredBodyParam('category');
        $file = UploadedFile::getInstanceByName('csvFile');

        if (!$file || !file_exists($file->tempName)) {
            Craft::$app->getSession()->setError(Craft::t('tka-translations', 'Please select a valid CSV file.'));
            return $this->redirect('tka-translations/import');
        }

        $content = file_get_contents($file->tempName);
        if ($content === false) {
            Craft::$app->getSession()->setError(Craft::t('tka-translations', 'Failed to read uploaded file.'));
            return $this->redirect('tka-translations/import');
        }

        $imported = Plugin::getInstance()->importExport->importCsvContent($content, $category);

        Craft::$app->getSession()->setNotice(
            Craft::t('tka-translations', 'Imported {count} translations for category "{cat}".', [
                'count' => $imported,
                'cat' => $category,
            ])
        );

        return $this->redirect('tka-translations');
    }
}
