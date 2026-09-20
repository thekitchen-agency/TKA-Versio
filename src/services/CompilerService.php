<?php

declare(strict_types=1);

namespace thekitchenagency\translations\services;

use Craft;
use craft\db\Query;
use craft\helpers\FileHelper;
use thekitchenagency\translations\Plugin;
use yii\base\Component;
use Exception;

/**
 * Service to compile database translations into static PHP files for zero-query OPcache performance.
 */
class CompilerService extends Component
{
    /**
     * Compile all categories and locales into static PHP translation files.
     *
     * @param string|null $targetCategory Optional category to compile, or null for all
     * @return array<string, int> Compiled stats: [locale/category => messageCount]
     */
    public function compile(?string $targetCategory = null): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $targetDir = Craft::getAlias($settings->compilationPath);
        FileHelper::createDirectory($targetDir);

        $categories = $targetCategory !== null ? [$targetCategory] : $settings->getCategories();
        $siteLocales = Craft::$app->getI18n()->getSiteLocales();

        $stats = [];

        foreach ($categories as $category) {
            foreach ($siteLocales as $locale) {
                $localeId = $locale->id;
                $messages = $this->getTranslationsForLocaleAndCategory($category, $localeId);

                // Path: @translations/{locale}/{category}.php
                $localeDir = $targetDir . DIRECTORY_SEPARATOR . $localeId;
                FileHelper::createDirectory($localeDir);
                $filePath = $localeDir . DIRECTORY_SEPARATOR . $category . '.php';

                $this->writePhpTranslationFile($filePath, $messages, $category, $localeId);
                $stats["{$localeId}/{$category}"] = count($messages);
            }
        }

        return $stats;
    }

    /**
     * Fetch all translated keys for a category and language from the database.
     *
     * @return array<string, string>
     */
    public function getTranslationsForLocaleAndCategory(string $category, string $language): array
    {
        $rows = (new Query())
            ->select(['s.message', 'm.translation'])
            ->from('{{%tka_source_messages}} s')
            ->innerJoin('{{%tka_messages}} m', 'm.id = s.id')
            ->where([
                's.category' => $category,
                'm.language' => $language,
            ])
            ->andWhere(['not', ['m.translation' => null]])
            ->andWhere(['!=', 'm.translation', ''])
            ->all();

        $dictionary = [];
        foreach ($rows as $row) {
            $dictionary[$row['message']] = $row['translation'];
        }

        ksort($dictionary);
        return $dictionary;
    }

    /**
     * Atomically write a clean PHP array file for OPcache.
     */
    protected function writePhpTranslationFile(string $filePath, array $messages, string $category, string $language): void
    {
        $date = (new \DateTime())->format('Y-m-d H:i:s');
        $code = "<?php\n\n";
        $code .= "/**\n";
        $code .= " * Auto-generated translation file for TKA Translations.\n";
        $code .= " * Category: {$category}\n";
        $code .= " * Language: {$language}\n";
        $code .= " * Generated: {$date}\n";
        $code .= " * DO NOT EDIT DIRECTLY - Managed via Craft CP.\n";
        $code .= " */\n\n";
        $code .= "return [\n";

        foreach ($messages as $key => $translation) {
            $exportedKey = var_export($key, true);
            $exportedValue = var_export($translation, true);
            $code .= "    {$exportedKey} => {$exportedValue},\n";
        }

        $code .= "];\n";

        // Atomic write to prevent partial reads by web server threads
        $tempFile = $filePath . '.' . bin2hex(random_bytes(6)) . '.tmp';
        file_put_contents($tempFile, $code, LOCK_EX);
        rename($tempFile, $filePath);

        // Invalidate OPcache if enabled
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($filePath, true);
        }
    }
}
