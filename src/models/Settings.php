<?php

declare(strict_types=1);

namespace thekitchenagency\translations\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;

/**
 * Settings model for TKA Translations.
 */
class Settings extends Model
{
    public string $pluginName = 'TKA Versio';
    public string $sourceLanguage = '';
    public array $categories = ['site'];
    public bool $compileToFiles = true;
    public string $compilationPath = '@translations';
    public bool $addMissingTranslations = false;
    public bool $addMissingSiteRequestOnly = true;
    public array $scanPaths = [];
    public array $excludedMessages = [];
    public array $translatableSections = []; // Empty means all multi-site sections

    // AI Translation Settings
    public string $defaultAiProvider = 'deepl'; // 'deepl', 'openai', 'gemini'
    public ?string $deeplApiKey = null;
    public string $deeplApiType = 'free'; // 'free', 'pro'
    public ?string $openaiApiKey = null;
    public string $openaiModel = 'gpt-4o-mini';
    public ?string $geminiApiKey = null;
    public string $geminiModel = 'gemini-flash-latest';

    public function defineRules(): array
    {
        return [
            [['pluginName', 'compilationPath', 'defaultAiProvider', 'deeplApiKey', 'deeplApiType', 'openaiApiKey', 'openaiModel', 'geminiApiKey', 'geminiModel'], 'string'],
            [['categories', 'scanPaths', 'excludedMessages', 'translatableSections'], 'safe'],
            [['compileToFiles', 'addMissingTranslations', 'addMissingSiteRequestOnly'], 'boolean'],
            ['categories', 'default', 'value' => ['site']],
        ];
    }

    public function getSourceLanguage(): string
    {
        return $this->sourceLanguage ?: Craft::$app->getSites()->getPrimarySite()->language;
    }

    public function getCategories(): array
    {
        if (empty($this->categories)) {
            return ['site'];
        }

        $cats = [];
        foreach ($this->categories as $category) {
            if (is_array($category)) {
                $cats[] = (string)($category['category'] ?? $category['value'] ?? '');
            } elseif (is_string($category)) {
                $cats[] = $category;
            }
        }

        $cleaned = array_values(array_unique(array_filter(array_map('trim', $cats))));
        return !empty($cleaned) ? $cleaned : ['site'];
    }

    public function getScanPaths(): array
    {
        if (empty($this->scanPaths)) {
            return ['@templates'];
        }

        $paths = [];
        foreach ($this->scanPaths as $entry) {
            if (is_array($entry)) {
                $paths[] = (string)($entry['path'] ?? $entry['value'] ?? '');
            } elseif (is_string($entry)) {
                $paths[] = $entry;
            }
        }

        $cleaned = array_values(array_unique(array_filter(array_map('trim', $paths))));
        return !empty($cleaned) ? $cleaned : ['@templates'];
    }

    public function getExcludedMessages(): array
    {
        if (empty($this->excludedMessages)) {
            return [];
        }

        $patterns = [];
        foreach ($this->excludedMessages as $pattern) {
            if (is_array($pattern)) {
                $patterns[] = (string)($pattern['pattern'] ?? $pattern['value'] ?? '');
            } elseif (is_string($pattern)) {
                $patterns[] = $pattern;
            }
        }

        return array_values(array_unique(array_filter(array_map('trim', $patterns))));
    }

    public function getDeeplApiKey(): ?string
    {
        return App::parseEnv($this->deeplApiKey);
    }

    public function getOpenaiApiKey(): ?string
    {
        return App::parseEnv($this->openaiApiKey);
    }

    public function getGeminiApiKey(): ?string
    {
        return App::parseEnv($this->geminiApiKey);
    }

    public function hasAiTranslationConfigured(): bool
    {
        return !empty($this->getDeeplApiKey())
            || !empty($this->getOpenaiApiKey())
            || !empty($this->getGeminiApiKey());
    }
}
