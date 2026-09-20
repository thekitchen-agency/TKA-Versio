<?php

declare(strict_types=1);

namespace thekitchenagency\translations\controllers;

use Craft;
use craft\web\Controller;
use thekitchenagency\translations\Plugin;
use thekitchenagency\translations\web\assets\cp\CpAsset;
use yii\web\Response;

/**
 * Controller for managing plugin settings.
 */
class SettingsController extends Controller
{
    public function actionIndex(): Response
    {
        $this->getView()->registerAssetBundle(CpAsset::class);
        $settings = Plugin::getInstance()->getSettings();

        $aiStats = Plugin::getInstance()->ai->getUsageStats();

        $allSections = Craft::$app->getEntries()->getAllSections();
        $sectionOptions = [];
        foreach ($allSections as $s) {
            if (count($s->getSiteSettings()) > 1) {
                $sectionOptions[] = [
                    'label' => $s->name . " ({$s->handle})",
                    'value' => $s->handle,
                ];
            }
        }

        return $this->renderTemplate('tka-translations/settings', [
            'pluginName' => $settings->pluginName,
            'title' => Craft::t('tka-translations', 'Settings'),
            'settings' => $settings,
            'sectionOptions' => $sectionOptions,
            'aiStats' => $aiStats,
            'selectedSubnavItem' => 'settings',
        ]);
    }

    /**
     * Clear AI translation usage history.
     */
    public function actionClearAiLogs(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_SETTINGS);

        Plugin::getInstance()->ai->clearUsageStats();

        Craft::$app->getSession()->setNotice(Craft::t('tka-translations', 'AI usage logs and cost metrics reset.'));
        return $this->redirectToPostedUrl();
    }

    /**
     * Save plugin settings.
     */
    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_SETTINGS);

        $settings = $this->request->getBodyParam('settings', []);
        $plugin = Plugin::getInstance();

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings)) {
            Craft::$app->getSession()->setError(Craft::t('tka-translations', 'Could not save settings.'));
            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setNotice(Craft::t('tka-translations', 'Settings saved.'));
        return $this->redirectToPostedUrl();
    }
}
