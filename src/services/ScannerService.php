<?php

declare(strict_types=1);

namespace thekitchenagency\translations\services;

use Craft;
use craft\helpers\FileHelper;
use thekitchenagency\translations\Plugin;
use yii\base\Component;

/**
 * Service for scanning templates and code to discover translation keys.
 */
class ScannerService extends Component
{
    /**
     * Scan all site templates and insert missing translation keys.
     *
     * @return array{totalFiles: int, totalDiscovered: int, newAdded: int}
     */
    public function scanTemplates(): array
    {
        $templatePaths = $this->getTemplatePaths();
        $discovered = [];
        $totalFiles = 0;

        foreach ($templatePaths as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $files = FileHelper::findFiles($dir, [
                'only' => ['*.twig', '*.html', '*.php', '*.js', '*.ts', '*.vue'],
            ]);

            $totalFiles += count($files);

            foreach ($files as $file) {
                $content = @file_get_contents($file);
                if ($content === false) {
                    continue;
                }

                $this->extractTranslationsFromContent($content, $discovered);
            }
        }

        $newAdded = 0;
        foreach ($discovered as $item) {
            $id = Plugin::getInstance()->messages->addMessage(
                $item['message'],
                $item['category'],
                [],
                false // Don't compile on every single key addition
            );
            if ($id !== null) {
                $newAdded++;
            }
        }

        // Compile once after scan completes
        if ($newAdded > 0 && Plugin::getInstance()->getSettings()->compileToFiles) {
            Plugin::getInstance()->compiler->compile();
        }

        return [
            'totalFiles' => $totalFiles,
            'totalDiscovered' => count($discovered),
            'newAdded' => $newAdded,
        ];
    }

    /**
     * Extract translation keys using high-speed regex pattern matching.
     * Handles:
     * - 'Message'|t
     * - "Message"|t
     * - 'Message'|t('category')
     * - 'Message'|t(category='category')
     * - Craft::t('category', 'Message')
     * - Craft.t('category', 'Message')
     */
    public function extractTranslationsFromContent(string $content, array &$discovered): void
    {
        // 1. Single quotes: 'text'|t or 'text'|t('category')
        if (preg_match_all("/'([^'\\\\]*(?:\\\\.[^'\\\\]*)*)'\\s*\\|\\s*t(?:\\(\\s*(?:category\\s*=\\s*)?'([^'\\\\]*(?:\\\\.[^'\\\\]*)*)'\\s*\\))?/", $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $message = stripcslashes($match[1]);
                $category = !empty($match[2]) ? stripcslashes($match[2]) : 'site';

                if ($this->isValidMessage($message)) {
                    $key = $category . '::' . $message;
                    $discovered[$key] = [
                        'category' => $category,
                        'message' => $message,
                    ];
                }
            }
        }

        // 2. Double quotes: "text"|t or "text"|t("category")
        if (preg_match_all('/"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"\\s*\\|\\s*t(?:\\(\\s*(?:category\\s*=\\s*)"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"\\s*\\))?/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $message = stripcslashes($match[1]);
                $category = !empty($match[2]) ? stripcslashes($match[2]) : 'site';

                if ($this->isValidMessage($message)) {
                    $key = $category . '::' . $message;
                    $discovered[$key] = [
                        'category' => $category,
                        'message' => $message,
                    ];
                }
            }
        }

        // 3. Craft::t('category', 'message')
        if (preg_match_all("/Craft(?:::|\\.)t\\(\\s*['\"]([^'\"]+)['\"]\\s*,\\s*['\"]([^'\"]+)['\"]/s", $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $category = stripcslashes($match[1]);
                $message = stripcslashes($match[2]);

                if ($this->isValidMessage($message)) {
                    $key = $category . '::' . $message;
                    $discovered[$key] = [
                        'category' => $category,
                        'message' => $message,
                    ];
                }
            }
        }
    }

    /**
     * Determine if a discovered string is a valid translatable key.
     */
    protected function isValidMessage(string $message): bool
    {
        $trimmed = trim($message);
        if ($trimmed === '' || strlen($trimmed) > 5000) {
            return false;
        }

        $excluded = Plugin::getInstance()->getSettings()->getExcludedMessages();
        foreach ($excluded as $pattern) {
            if ($pattern !== '' && str_contains($trimmed, $pattern)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get all paths to scan for templates (supports aliases like @templates, @root/src, etc.).
     *
     * @return string[]
     */
    public function getTemplatePaths(): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $configuredPaths = $settings->getScanPaths();
        $resolved = [];

        foreach ($configuredPaths as $path) {
            $resolvedPath = Craft::getAlias($path);
            if (is_dir($resolvedPath)) {
                $resolved[] = $resolvedPath;
            }
        }

        return array_unique($resolved);
    }
}
