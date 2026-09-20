<?php

declare(strict_types=1);

namespace thekitchenagency\translations\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use GuzzleHttp\Client;
use thekitchenagency\translations\Plugin;
use yii\base\Component;
use Exception;

/**
 * Service for automated AI translation and cost tracking.
 */
class AiTranslationService extends Component
{
    /**
     * @var string|null Stores the last error message for UI feedback.
     */
    protected ?string $lastError = null;

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Translate a single text using the specified or default AI provider.
     */
    public function translate(string $text, string $targetLocale, string $sourceLocale = 'de', ?string $provider = null): ?string
    {
        $this->lastError = null;
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $settings = Plugin::getInstance()->getSettings();
        $provider = $provider ?: $settings->defaultAiProvider;

        // Auto-select fallback provider if requested provider is missing API key
        if ($provider === 'deepl' && empty($settings->getDeeplApiKey())) {
            $provider = !empty($settings->getOpenaiApiKey()) ? 'openai' : (!empty($settings->getGeminiApiKey()) ? 'gemini' : null);
        } elseif ($provider === 'openai' && empty($settings->getOpenaiApiKey())) {
            $provider = !empty($settings->getDeeplApiKey()) ? 'deepl' : (!empty($settings->getGeminiApiKey()) ? 'gemini' : null);
        } elseif ($provider === 'gemini' && empty($settings->getGeminiApiKey())) {
            $provider = !empty($settings->getDeeplApiKey()) ? 'deepl' : (!empty($settings->getOpenaiApiKey()) ? 'openai' : null);
        }

        if (!$provider) {
            $this->lastError = Craft::t('tka-translations', 'No AI translation provider API key configured. Check Settings.');
            Craft::error($this->lastError, __METHOD__);
            return null;
        }

        try {
            return match ($provider) {
                'deepl' => $this->translateWithDeepl($text, $targetLocale, $sourceLocale),
                'openai' => $this->translateWithOpenai($text, $targetLocale, $sourceLocale),
                'gemini' => $this->translateWithGemini($text, $targetLocale, $sourceLocale),
                default => null,
            };
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $this->lastError = $msg;
            Craft::error("AI translation error with provider [{$provider}]: " . $msg, __METHOD__);
            return null;
        }
    }

    /**
     * Translate via DeepL API.
     */
    protected function translateWithDeepl(string $text, string $targetLocale, string $sourceLocale): ?string
    {
        $settings = Plugin::getInstance()->getSettings();
        $apiKey = $settings->getDeeplApiKey();
        if (empty($apiKey)) {
            throw new Exception(Craft::t('tka-translations', 'DeepL API Key is missing. Check Settings.'));
        }

        $isFree = $settings->deeplApiType === 'free' || str_ends_with($apiKey, ':fx');
        $endpoint = $isFree
            ? 'https://api-free.deepl.com/v2/translate'
            : 'https://api.deepl.com/v2/translate';

        $targetLang = $this->formatDeeplTargetLang($targetLocale);

        $client = Craft::createGuzzleClient();

        try {
            $response = $client->post($endpoint, [
                'headers' => [
                    'Authorization' => 'DeepL-Auth-Key ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'text' => [$text],
                    'target_lang' => $targetLang,
                    'preserve_formatting' => true,
                ],
                'timeout' => 15,
            ]);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $code = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
            if ($code === 403) {
                throw new Exception(Craft::t('tka-translations', 'DeepL Authentication failed (403). Check your API Key and Plan Type (Free vs Pro).'));
            }
            if ($code === 456) {
                throw new Exception(Craft::t('tka-translations', 'DeepL Quota exceeded (456). You have reached your translation character limit.'));
            }
            throw new Exception("DeepL API error ({$code}): " . $e->getMessage());
        }

        $body = json_decode((string)$response->getBody(), true);
        $translation = $body['translations'][0]['text'] ?? null;

        if ($translation !== null) {
            $charCount = mb_strlen($text);
            $cost = $isFree ? 0.0 : ($charCount * 0.000025); // ~$25 per 1M chars
            $this->logUsage('deepl', $isFree ? 'deepl-free' : 'deepl-pro', $sourceLocale, $targetLocale, $charCount, 0, 0, $cost);
        }

        return $translation;
    }

    /**
     * Translate via OpenAI API (e.g. gpt-4o-mini).
     */
    protected function translateWithOpenai(string $text, string $targetLocale, string $sourceLocale): ?string
    {
        $settings = Plugin::getInstance()->getSettings();
        $apiKey = $settings->getOpenaiApiKey();
        if (empty($apiKey)) {
            throw new Exception(Craft::t('tka-translations', 'OpenAI API Key is missing. Check Settings.'));
        }

        $model = $settings->openaiModel ?: 'gpt-4o-mini';

        $systemPrompt = "You are a professional website and UI copy translator. Translate the provided web UI translation key accurately into {$targetLocale}. Preserve any template variables (e.g. {title}, {count}, %s), HTML tags, and punctuation exactly. Return ONLY the translated string without quotes or explanation.";

        $client = Craft::createGuzzleClient();

        try {
            $response = $client->post('https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $text],
                    ],
                    'temperature' => 0.2,
                ],
                'timeout' => 20,
            ]);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $code = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
            $errBody = $e->getResponse() ? (string)$e->getResponse()->getBody() : $e->getMessage();
            $errJson = json_decode($errBody, true);
            $msg = $errJson['error']['message'] ?? $e->getMessage();

            if ($code === 401) {
                throw new Exception(Craft::t('tka-translations', 'OpenAI Authentication failed (401). Check your API Key.'));
            }
            if ($code === 429) {
                throw new Exception(Craft::t('tka-translations', 'OpenAI Quota/Rate Limit exceeded (429). Check your OpenAI billing balance.'));
            }
            throw new Exception("OpenAI API error ({$code}): {$msg}");
        }

        $body = json_decode((string)$response->getBody(), true);
        $content = $body['choices'][0]['message']['content'] ?? null;
        $translation = $content !== null ? trim($content) : null;

        if ($translation !== null) {
            $promptTokens = (int)($body['usage']['prompt_tokens'] ?? 0);
            $completionTokens = (int)($body['usage']['completion_tokens'] ?? 0);
            $charCount = mb_strlen($text);

            $cost = match ($model) {
                'gpt-4o' => ($promptTokens * 0.0000025) + ($completionTokens * 0.000010),
                'gpt-3.5-turbo' => ($promptTokens * 0.0000005) + ($completionTokens * 0.0000015),
                default => ($promptTokens * 0.00000015) + ($completionTokens * 0.0000006), // gpt-4o-mini
            };

            $this->logUsage('openai', $model, $sourceLocale, $targetLocale, $charCount, $promptTokens, $completionTokens, $cost);
        }

        return $translation;
    }

    /**
     * Translate via Google Gemini API (e.g. gemini-flash-latest, gemini-3.6-flash).
     */
    protected function translateWithGemini(string $text, string $targetLocale, string $sourceLocale): ?string
    {
        $settings = Plugin::getInstance()->getSettings();
        $apiKey = $settings->getGeminiApiKey();
        if (empty($apiKey)) {
            throw new Exception(Craft::t('tka-translations', 'Gemini API Key is missing. Check Settings.'));
        }

        $model = $settings->geminiModel ?: 'gemini-flash-latest';

        // Auto-map legacy or deprecated model names to current active endpoints
        $modelMap = [
            'gemini-1.5-flash' => 'gemini-flash-latest',
            'gemini-1.5-pro' => 'gemini-pro-latest',
            'gemini-2.0-flash' => 'gemini-flash-latest',
            'gemini-2.5-flash' => 'gemini-flash-latest',
        ];
        $model = $modelMap[$model] ?? $model;

        $systemPrompt = "You are a professional website and UI copy translator. Translate the provided string accurately into {$targetLocale}. Preserve all placeholders like {title} or %s, HTML tags, and markdown formatting. Return ONLY the raw translated text with no extra commentary, explanations, or quotes.";

        $client = Craft::createGuzzleClient();
        $payload = [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => "{$systemPrompt}\n\nTranslate this string:\n{$text}"],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.2,
                ],
            ],
            'timeout' => 20,
        ];

        $maxAttempts = 3;
        $response = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
                $response = $client->post($endpoint, $payload);
                break; // Success
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
                $errBody = $e->getResponse() ? (string)$e->getResponse()->getBody() : $e->getMessage();
                $errJson = json_decode($errBody, true);
                $apiMessage = $errJson['error']['message'] ?? $e->getMessage();

                // If 404 and not already fallback model, retry with gemini-flash-latest
                if ($statusCode === 404 && $model !== 'gemini-flash-latest') {
                    $model = 'gemini-flash-latest';
                    continue;
                }

                // If rate limited (429 / RESOURCE_EXHAUSTED), wait and retry
                if ($statusCode === 429 && $attempt < $maxAttempts) {
                    sleep($attempt * 2); // 2s, 4s backoff
                    continue;
                }

                if ($statusCode === 429) {
                    throw new Exception(Craft::t('tka-translations', 'Google Gemini Rate Limit: Free tier quota exceeded (5-15 req/min). Please wait a moment before trying again.'));
                }

                throw new Exception("Gemini API error ({$statusCode}): {$apiMessage}");
            } catch (\GuzzleHttp\Exception\ServerException $e) {
                // 500 or 503 server overloaded
                if ($attempt < $maxAttempts) {
                    sleep(2);
                    continue;
                }
                throw new Exception(Craft::t('tka-translations', 'Google Gemini server is currently busy (503). Please retry in a few seconds.'));
            }
        }

        if (!$response) {
            throw new Exception(Craft::t('tka-translations', 'No response received from Gemini API.'));
        }

        $body = json_decode((string)$response->getBody(), true);
        $content = $body['candidates'][0]['content']['parts'][0]['text'] ?? null;
        $translation = $content !== null ? trim($content) : null;

        if ($translation !== null) {
            $promptTokens = (int)($body['usageMetadata']['promptTokenCount'] ?? 0);
            $completionTokens = (int)($body['usageMetadata']['candidatesTokenCount'] ?? 0);
            $charCount = mb_strlen($text);

            $cost = match ($model) {
                'gemini-pro-latest', 'gemini-1.5-pro' => ($promptTokens * 0.00000125) + ($completionTokens * 0.000005),
                'gemini-2.5-flash-lite' => ($promptTokens * 0.00000005) + ($completionTokens * 0.0000002),
                default => ($promptTokens * 0.000000075) + ($completionTokens * 0.0000003), // gemini-flash-latest / 3.6-flash
            };

            $this->logUsage('gemini', $model, $sourceLocale, $targetLocale, $charCount, $promptTokens, $completionTokens, $cost);
        }

        return $translation;
    }

    /**
     * Log an AI translation request and estimated cost to the database.
     */
    protected function logUsage(string $provider, string $model, string $sourceLocale, string $targetLocale, int $chars, int $inputTokens, int $outputTokens, float $cost): void
    {
        try {
            $now = Db::prepareDateForDb(new \DateTime());
            Craft::$app->getDb()->createCommand()->insert(
                '{{%tka_ai_usage}}',
                [
                    'provider' => $provider,
                    'model' => $model,
                    'sourceLocale' => $sourceLocale,
                    'targetLocale' => $targetLocale,
                    'charactersCount' => $chars,
                    'inputTokens' => $inputTokens,
                    'outputTokens' => $outputTokens,
                    'estimatedCostUsd' => round($cost, 6),
                    'dateCreated' => $now,
                    'uid' => StringHelper::UUID(),
                ]
            )->execute();
        } catch (\Throwable $e) {
            Craft::warning("Failed to log AI translation usage: " . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Get aggregate usage and cost statistics for the dashboard.
     *
     * @return array{totalCost: float, totalRequests: int, totalChars: int, totalTokens: int, byProvider: array, recentLogs: array}
     */
    public function getUsageStats(): array
    {
        $db = Craft::$app->getDb();
        if (!$db->tableExists('{{%tka_ai_usage}}')) {
            return [
                'totalCost' => 0.0,
                'totalRequests' => 0,
                'totalChars' => 0,
                'totalTokens' => 0,
                'byProvider' => [],
                'recentLogs' => [],
            ];
        }

        $totalRow = (new Query())
            ->select([
                'totalCost' => 'SUM(estimatedCostUsd)',
                'totalRequests' => 'COUNT(id)',
                'totalChars' => 'SUM(charactersCount)',
                'totalTokens' => 'SUM(inputTokens + outputTokens)',
            ])
            ->from('{{%tka_ai_usage}}')
            ->one();

        $providerRows = (new Query())
            ->select([
                'provider',
                'requests' => 'COUNT(id)',
                'cost' => 'SUM(estimatedCostUsd)',
                'characters' => 'SUM(charactersCount)',
                'tokens' => 'SUM(inputTokens + outputTokens)',
            ])
            ->from('{{%tka_ai_usage}}')
            ->groupBy(['provider'])
            ->all();

        $byProvider = [];
        foreach ($providerRows as $pRow) {
            $byProvider[$pRow['provider']] = [
                'requests' => (int)$pRow['requests'],
                'cost' => (float)$pRow['cost'],
                'characters' => (int)$pRow['characters'],
                'tokens' => (int)$pRow['tokens'],
            ];
        }

        $recentLogs = (new Query())
            ->from('{{%tka_ai_usage}}')
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit(10)
            ->all();

        return [
            'totalCost' => (float)($totalRow['totalCost'] ?? 0.0),
            'totalRequests' => (int)($totalRow['totalRequests'] ?? 0),
            'totalChars' => (int)($totalRow['totalChars'] ?? 0),
            'totalTokens' => (int)($totalRow['totalTokens'] ?? 0),
            'byProvider' => $byProvider,
            'recentLogs' => $recentLogs,
        ];
    }

    /**
     * Reset / clear usage logs.
     */
    public function clearUsageStats(): void
    {
        Craft::$app->getDb()->createCommand()->truncateTable('{{%tka_ai_usage}}')->execute();
    }

    /**
     * Convert Craft locale to DeepL supported target language code.
     */
    protected function formatDeeplTargetLang(string $locale): string
    {
        $loc = strtoupper(str_replace('_', '-', $locale));

        if ($loc === 'EN' || str_starts_with($loc, 'EN-')) {
            return 'EN-US';
        }
        if (str_starts_with($loc, 'PT-BR')) {
            return 'PT-BR';
        }
        if (str_starts_with($loc, 'PT')) {
            return 'PT-PT';
        }

        $parts = explode('-', $loc);
        return $parts[0];
    }
}
