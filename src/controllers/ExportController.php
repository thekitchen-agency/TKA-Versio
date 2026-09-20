<?php

declare(strict_types=1);

namespace thekitchenagency\translations\controllers;

use Craft;
use craft\web\Controller;
use thekitchenagency\translations\Plugin;
use thekitchenagency\translations\web\assets\cp\CpAsset;
use yii\web\Response;

/**
 * Controller for exporting translations.
 */
class ExportController extends Controller
{
    public function actionIndex(): Response
    {
        $this->getView()->registerAssetBundle(CpAsset::class);
        $settings = Plugin::getInstance()->getSettings();

        return $this->renderTemplate('tka-translations/export', [
            'pluginName' => $settings->pluginName,
            'title' => Craft::t('tka-translations', 'Export Translations'),
            'categories' => $settings->getCategories(),
            'selectedSubnavItem' => 'export',
        ]);
    }

    /**
     * Download exported CSV or JSON.
     */
    public function actionDownload(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_MANAGE_TRANSLATIONS);

        $category = (string)$this->request->getParam('category', 'site');
        $format = (string)$this->request->getParam('format', 'csv');

        $date = (new \DateTime())->format('Y-m-d');
        $filename = "translations-{$category}-{$date}.{$format}";

        if ($format === 'json') {
            $content = Plugin::getInstance()->importExport->exportJson($category);
            return $this->response->sendContentAsFile($content, $filename, [
                'mimeType' => 'application/json',
            ]);
        }

        $content = Plugin::getInstance()->importExport->exportCsv($category);
        return $this->response->sendContentAsFile($content, $filename, [
            'mimeType' => 'text/csv',
        ]);
    }
}
