<?php

declare(strict_types=1);

namespace thekitchenagency\translations;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\services\UserPermissions;
use craft\web\twig\variables\Cp;
use craft\web\UrlManager;
use thekitchenagency\translations\models\Settings;
use thekitchenagency\translations\services\AiTranslationService;
use thekitchenagency\translations\services\CompilerService;
use thekitchenagency\translations\services\EntryTranslationService;
use thekitchenagency\translations\services\ImportExportService;
use thekitchenagency\translations\services\MessagesService;
use thekitchenagency\translations\services\ScannerService;
use yii\base\Event;

/**
 * TKA Translations Plugin for Craft CMS 5.
 *
 * @property AiTranslationService $ai
 * @property CompilerService $compiler
 * @property MessagesService $messages
 * @property ScannerService $scanner
 * @property ImportExportService $importExport
 * @property EntryTranslationService $entryTranslation
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const PERMISSION_MANAGE_TRANSLATIONS = 'tkaTranslations:manage';
    public const PERMISSION_MANAGE_SETTINGS = 'tkaTranslations:settings';

    public string $schemaVersion = '1.1.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'ai' => AiTranslationService::class,
                'compiler' => CompilerService::class,
                'messages' => MessagesService::class,
                'scanner' => ScannerService::class,
                'importExport' => ImportExportService::class,
                'entryTranslation' => EntryTranslationService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->name = $this->getSettings()->pluginName ?: 'TKA Versio';

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'thekitchenagency\translations\console\controllers';
        }

        $this->registerCpRoutes();
        $this->registerPermissions();
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        if ($item === null) {
            return null;
        }

        $user = Craft::$app->getUser()->getIdentity();
        $canManage = $user?->can(self::PERMISSION_MANAGE_TRANSLATIONS) ?? false;
        $canSettings = $user?->can(self::PERMISSION_MANAGE_SETTINGS) ?? false;

        $subnav = [];
        if ($canManage) {
            $subnav['messages'] = ['label' => Craft::t('tka-translations', 'Messages'), 'url' => 'tka-translations'];
            $subnav['entries'] = ['label' => Craft::t('tka-translations', 'Entries'), 'url' => 'tka-translations/entries'];
            $subnav['scan'] = ['label' => Craft::t('tka-translations', 'Scan Templates'), 'url' => 'tka-translations/scan'];
            $subnav['import'] = ['label' => Craft::t('tka-translations', 'Import'), 'url' => 'tka-translations/import'];
            $subnav['export'] = ['label' => Craft::t('tka-translations', 'Export'), 'url' => 'tka-translations/export'];
        }
        if ($canSettings) {
            $subnav['settings'] = ['label' => Craft::t('tka-translations', 'Settings'), 'url' => 'tka-translations/settings'];
        }

        $item['subnav'] = $subnav;
        return $item;
    }

    protected function cpNavIconPath(): ?string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'icon-mask.svg';
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('tka-translations/settings'));
    }

    private function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['tka-translations'] = 'tka-translations/messages/index';
                $event->rules['tka-translations/category/<category:[\w\-\.\/]+>'] = 'tka-translations/messages/index';
                $event->rules['tka-translations/entries'] = 'tka-translations/entries/index';
                $event->rules['tka-translations/scan'] = 'tka-translations/scanner/index';
                $event->rules['tka-translations/import'] = 'tka-translations/import/index';
                $event->rules['tka-translations/export'] = 'tka-translations/export/index';
                $event->rules['tka-translations/settings'] = 'tka-translations/settings/index';
            }
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('tka-translations', 'TKA Versio'),
                    'permissions' => [
                        self::PERMISSION_MANAGE_TRANSLATIONS => [
                            'label' => Craft::t('tka-translations', 'Manage translations (edit, scan, import, export)'),
                        ],
                        self::PERMISSION_MANAGE_SETTINGS => [
                            'label' => Craft::t('tka-translations', 'Manage translation settings'),
                        ],
                    ],
                ];
            }
        );
    }
}
