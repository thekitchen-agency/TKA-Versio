<?php

declare(strict_types=1);

namespace thekitchenagency\translations\services;

use Craft;
use craft\db\Query;
use craft\helpers\FileHelper;
use thekitchenagency\translations\Plugin;
use yii\base\Component;

/**
 * Service for importing and exporting translations (CSV, JSON, PHP).
 */
class ImportExportService extends Component
{
    /**
     * Export category messages as CSV string.
     */
    public function exportCsv(string $category): string
    {
        $siteLocales = Craft::$app->getI18n()->getSiteLocales();
        $localeIds = array_map(fn($l) => $l->id, $siteLocales);

        $fp = fopen('php://temp', 'r+');
        if ($fp === false) {
            return '';
        }

        // Header row: Key, en-US, de-CH, etc.
        $headers = array_merge(['Key'], $localeIds);
        fputcsv($fp, $headers);

        $sourceRows = (new Query())
            ->select(['id', 'message'])
            ->from('{{%tka_source_messages}}')
            ->where(['category' => $category])
            ->orderBy(['message' => SORT_ASC])
            ->all();

        if (!empty($sourceRows)) {
            $sourceIds = array_column($sourceRows, 'id');
            $messageRows = (new Query())
                ->select(['id', 'language', 'translation'])
                ->from('{{%tka_messages}}')
                ->where(['id' => $sourceIds])
                ->all();

            $translations = [];
            foreach ($messageRows as $row) {
                $translations[$row['id']][$row['language']] = $row['translation'];
            }

            foreach ($sourceRows as $sRow) {
                $id = $sRow['id'];
                $line = [$sRow['message']];

                foreach ($localeIds as $loc) {
                    $line[] = $translations[$id][$loc] ?? '';
                }

                fputcsv($fp, $line);
            }
        }

        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);

        return $csv ?: '';
    }

    /**
     * Export category messages as JSON string.
     */
    public function exportJson(string $category): string
    {
        $siteLocales = Craft::$app->getI18n()->getSiteLocales();
        $localeIds = array_map(fn($l) => $l->id, $siteLocales);

        $sourceRows = (new Query())
            ->select(['id', 'message'])
            ->from('{{%tka_source_messages}}')
            ->where(['category' => $category])
            ->orderBy(['message' => SORT_ASC])
            ->all();

        $data = [];
        if (!empty($sourceRows)) {
            $sourceIds = array_column($sourceRows, 'id');
            $messageRows = (new Query())
                ->select(['id', 'language', 'translation'])
                ->from('{{%tka_messages}}')
                ->where(['id' => $sourceIds])
                ->all();

            $translations = [];
            foreach ($messageRows as $row) {
                $translations[$row['id']][$row['language']] = $row['translation'];
            }

            foreach ($sourceRows as $sRow) {
                $id = $sRow['id'];
                $msg = $sRow['message'];
                $item = [];

                foreach ($localeIds as $loc) {
                    $item[$loc] = $translations[$id][$loc] ?? '';
                }

                $data[$msg] = $item;
            }
        }

        return (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Import existing Craft PHP translation files into the database.
     *
     * @return array{totalRead: int, totalImported: int}
     */
    public function importExistingPhpFiles(): array
    {
        $translationsPath = Craft::getAlias(Plugin::getInstance()->getSettings()->compilationPath);
        $categories = Plugin::getInstance()->getSettings()->getCategories();
        $siteLocales = Craft::$app->getI18n()->getSiteLocales();
        $siteLocaleIds = array_map(fn($l) => $l->id, $siteLocales);

        $totalRead = 0;
        $batch = [];

        if (!is_dir($translationsPath)) {
            return ['totalRead' => 0, 'totalImported' => 0];
        }

        $dirs = scandir($translationsPath);
        if ($dirs === false) {
            return ['totalRead' => 0, 'totalImported' => 0];
        }

        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..' || !is_dir($translationsPath . DIRECTORY_SEPARATOR . $dir)) {
                continue;
            }

            $folderLocale = $dir; // e.g. "de", "de-CH", "en"

            foreach ($categories as $category) {
                $file = FileHelper::normalizePath("{$translationsPath}/{$folderLocale}/{$category}.php");
                if (!file_exists($file)) {
                    continue;
                }

                $fileData = @include($file);
                if (!is_array($fileData)) {
                    continue;
                }

                foreach ($fileData as $msgKey => $translation) {
                    $totalRead++;
                    $msgKey = (string)$msgKey;
                    $translation = (string)$translation;

                    // If folderLocale is an exact match for a site locale
                    if (in_array($folderLocale, $siteLocaleIds, true)) {
                        $batch[$category][$msgKey][$folderLocale] = $translation;
                    }

                    // Also check if any site locale has this folder as its base language (e.g. de-CH matches de)
                    foreach ($siteLocaleIds as $sLocId) {
                        if (str_starts_with($sLocId, $folderLocale . '-') && !isset($batch[$category][$msgKey][$sLocId])) {
                            $batch[$category][$msgKey][$sLocId] = $translation;
                        }
                    }
                }
            }
        }

        $totalImported = 0;
        foreach ($batch as $category => $messages) {
            foreach ($messages as $message => $translations) {
                $sourceId = Plugin::getInstance()->messages->addMessage($message, $category, $translations, false);
                if ($sourceId !== null) {
                    // Update any additional translations
                    Plugin::getInstance()->messages->saveBatch([$sourceId => $translations], $category, false);
                    $totalImported++;
                }
            }
        }

        if ($totalImported > 0 && Plugin::getInstance()->getSettings()->compileToFiles) {
            Plugin::getInstance()->compiler->compile();
        }

        return [
            'totalRead' => $totalRead,
            'totalImported' => $totalImported,
        ];
    }

    /**
     * Import translations from uploaded CSV content.
     */
    public function importCsvContent(string $csvContent, string $category): int
    {
        $lines = explode("\n", $csvContent);
        if (empty($lines)) {
            return 0;
        }

        $headerLine = array_shift($lines);
        $headers = str_getcsv((string)$headerLine);
        if (empty($headers) || count($headers) < 2) {
            return 0;
        }

        // Header format: [Key, en-US, de-CH, ...]
        $locales = array_slice($headers, 1);
        $imported = 0;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $row = str_getcsv($line);
            $message = $row[0] ?? '';
            if (trim($message) === '') {
                continue;
            }

            $translations = [];
            for ($i = 0; $i < count($locales); $i++) {
                $loc = trim($locales[$i]);
                $val = $row[$i + 1] ?? '';
                if ($val !== '') {
                    $translations[$loc] = $val;
                }
            }

            $sourceId = Plugin::getInstance()->messages->addMessage($message, $category, $translations, false);
            if ($sourceId !== null) {
                if (!empty($translations)) {
                    Plugin::getInstance()->messages->saveBatch([$sourceId => $translations], $category, false);
                }
                $imported++;
            }
        }

        if ($imported > 0 && Plugin::getInstance()->getSettings()->compileToFiles) {
            Plugin::getInstance()->compiler->compile($category);
        }

        return $imported;
    }
}
