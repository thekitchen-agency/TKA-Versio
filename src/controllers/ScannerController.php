<?php

declare(strict_types=1);

namespace thekitchenagency\translations\controllers;

use Craft;
use craft\web\Controller;
use thekitchenagency\translations\jobs\ScanTemplatesJob;
use thekitchenagency\translations\Plugin;
use thekitchenagency\translations\web\assets\cp\CpAsset;
use yii\web\Response;

/**
 * Controller for scanning templates.
 */
class ScannerController extends Controller
{
    public function actionIndex(): Response
    {
        $this->getView()->registerAssetBundle(CpAsset::class);
        $settings = Plugin::getInstance()->getSettings();

        return $this->renderTemplate('tka-translations/scan', [
            'pluginName' => $settings->pluginName,
            'title' => Craft::t('tka-translations', 'Scan Templates'),
            'selectedSubnavItem' => 'scan',
            'templatePaths' => Plugin::getInstance()->scanner->getTemplatePaths(),
        ]);
    }

    /**
     * Trigger background queue scan.
     */
    public function actionRun(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_TRANSLATIONS);

        $jobId = Craft::$app->getQueue()->push(new ScanTemplatesJob());

        if ($this->request->getAcceptsJson()) {
            return $this->asJson([
                'success' => true,
                'jobId' => $jobId,
                'message' => Craft::t('tka-translations', 'Template scan queued.'),
            ]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('tka-translations', 'Template scan added to queue.'));
        return $this->redirect('tka-translations/scan');
    }
}
