<?php

declare(strict_types=1);

namespace thekitchenagency\translations\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use thekitchenagency\translations\Plugin;
use yii\console\ExitCode;

/**
 * CLI commands for TKA Translations.
 */
class UtilitiesController extends Controller
{
    /**
     * Compile all database translations to static PHP files.
     *
     * Example: ./craft tka-translations/utilities/compile
     */
    public function actionCompile(?string $category = null): int
    {
        $this->stdout("Compiling translations to static PHP files...\n", Console::FG_YELLOW);

        $stats = Plugin::getInstance()->compiler->compile($category);

        foreach ($stats as $target => $count) {
            $this->stdout("  ✔ {$target}: {$count} translations compiled\n", Console::FG_GREEN);
        }

        $this->stdout("All translations compiled successfully.\n", Console::FG_GREEN, Console::BOLD);
        return ExitCode::OK;
    }

    /**
     * Scan templates and extract missing translation keys into the database.
     *
     * Example: ./craft tka-translations/utilities/scan
     */
    public function actionScan(): int
    {
        $this->stdout("Scanning templates for translation keys...\n", Console::FG_YELLOW);

        $results = Plugin::getInstance()->scanner->scanTemplates();

        $this->stdout("  Files scanned: {$results['totalFiles']}\n");
        $this->stdout("  Total keys found: {$results['totalDiscovered']}\n");
        $this->stdout("  New keys added: {$results['newAdded']}\n", Console::FG_GREEN, Console::BOLD);

        return ExitCode::OK;
    }

    /**
     * Import existing static PHP translation files into the database.
     *
     * Example: ./craft tka-translations/utilities/import-php
     */
    public function actionImportPhp(): int
    {
        $this->stdout("Importing static PHP translation files into database...\n", Console::FG_YELLOW);

        $results = Plugin::getInstance()->importExport->importExistingPhpFiles();

        $this->stdout("  Total entries read: {$results['totalRead']}\n");
        $this->stdout("  Imported into database: {$results['totalImported']}\n", Console::FG_GREEN, Console::BOLD);

        return ExitCode::OK;
    }
}
