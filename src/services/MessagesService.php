<?php

declare(strict_types=1);

namespace thekitchenagency\translations\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use thekitchenagency\translations\models\SourceMessage;
use thekitchenagency\translations\Plugin;
use yii\base\Component;
use yii\base\Event;

/**
 * High-performance database service for managing translations.
 */
class MessagesService extends Component
{
    public const EVENT_AFTER_SAVE_TRANSLATIONS = 'afterSaveTranslations';
    public const EVENT_AFTER_ADD_MESSAGE = 'afterAddMessage';
    public const EVENT_AFTER_DELETE_MESSAGES = 'afterDeleteMessages';

    /**
     * Get paginated and filtered translation records for the CP table.
     */
    public function getPaginated(string $category, array $params = []): array
    {
        $siteLocales = Craft::$app->getI18n()->getSiteLocales();
        $localeIds = array_map(fn($l) => $l->id, $siteLocales);

        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = min(200, max(10, (int)($params['perPage'] ?? 50)));
        $search = trim((string)($params['search'] ?? ''));
        $status = (string)($params['status'] ?? 'all'); // 'all', 'missing', 'translated'
        $sort = (string)($params['sort'] ?? 'message');
        $direction = strtolower((string)($params['dir'] ?? 'asc')) === 'desc' ? SORT_DESC : SORT_ASC;

        // Base query for source message IDs
        $sourceQuery = (new Query())
            ->from('{{%tka_source_messages}} s')
            ->where(['s.category' => $category]);

        if ($search !== '') {
            $sourceQuery->andWhere([
                'or',
                ['like', 's.message', $search],
                ['in', 's.id', (new Query())
                    ->select('m.id')
                    ->from('{{%tka_messages}} m')
                    ->where(['like', 'm.translation', $search])
                ],
            ]);
        }

        if ($status === 'missing') {
            // Keys where at least one locale is empty
            $sourceQuery->andWhere([
                'or',
                // Has fewer translations than site locales
                ['<', (new Query())
                    ->select('COUNT(m.language)')
                    ->from('{{%tka_messages}} m')
                    ->where('m.id = s.id')
                    ->andWhere(['not', ['m.translation' => null]])
                    ->andWhere(['!=', 'm.translation', '']),
                    count($localeIds)
                ],
            ]);
        } elseif ($status === 'translated') {
            // Keys where all locales have translations
            $sourceQuery->andWhere([
                '>=', (new Query())
                    ->select('COUNT(m.language)')
                    ->from('{{%tka_messages}} m')
                    ->where('m.id = s.id')
                    ->andWhere(['not', ['m.translation' => null]])
                    ->andWhere(['!=', 'm.translation', '']),
                count($localeIds)
            ]);
        }

        $total = (int)$sourceQuery->count();

        // Apply sorting
        if ($sort === 'dateCreated') {
            $sourceQuery->orderBy(['s.dateCreated' => $direction]);
        } else {
            $sourceQuery->orderBy(['s.message' => $direction]);
        }

        // Apply pagination
        $offset = ($page - 1) * $perPage;
        $sourceRows = $sourceQuery->offset($offset)->limit($perPage)->all();

        if (empty($sourceRows)) {
            return [
                'items' => [],
                'total' => $total,
                'page' => $page,
                'perPage' => $perPage,
                'totalPages' => max(1, (int)ceil($total / $perPage)),
            ];
        }

        $sourceIds = array_column($sourceRows, 'id');

        // Fetch all translations for these specific source IDs in one batch query
        $messageRows = (new Query())
            ->select(['id', 'language', 'translation'])
            ->from('{{%tka_messages}}')
            ->where(['id' => $sourceIds])
            ->all();

        $messagesBySourceId = [];
        foreach ($messageRows as $mRow) {
            $messagesBySourceId[$mRow['id']][$mRow['language']] = $mRow['translation'];
        }

        $items = [];
        foreach ($sourceRows as $sRow) {
            $id = (int)$sRow['id'];
            $translations = [];

            foreach ($localeIds as $localeId) {
                $translations[$localeId] = $messagesBySourceId[$id][$localeId] ?? '';
            }

            $items[] = [
                'id' => $id,
                'category' => $sRow['category'],
                'message' => $sRow['message'],
                'translations' => $translations,
                'dateCreated' => $sRow['dateCreated'],
                'dateUpdated' => $sRow['dateUpdated'],
            ];
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => max(1, (int)ceil($total / $perPage)),
        ];
    }

    /**
     * Add a new single source message.
     */
    public function addMessage(string $message, string $category = 'site', array $translations = [], bool $compile = true): ?int
    {
        $message = trim($message);
        if ($message === '') {
            return null;
        }

        $hash = SourceMessage::hash($category, $message);
        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new \DateTime());

        // Check if exists
        $existingId = (new Query())
            ->select('id')
            ->from('{{%tka_source_messages}}')
            ->where(['category' => $category, 'messageHash' => $hash])
            ->scalar();

        if ($existingId) {
            return (int)$existingId;
        }

        $transaction = $db->beginTransaction();
        try {
            $uid = StringHelper::UUID();
            $db->createCommand()->insert(
                '{{%tka_source_messages}}',
                [
                    'category' => $category,
                    'message' => $message,
                    'messageHash' => $hash,
                    'dateCreated' => $now,
                    'dateUpdated' => $now,
                    'uid' => $uid,
                ]
            )->execute();

            $sourceId = (int)$db->getLastInsertID('{{%tka_source_messages}}');

            // Insert initial translations if provided
            if (!empty($translations)) {
                $rows = [];
                foreach ($translations as $lang => $text) {
                    if ($text !== null && trim($text) !== '') {
                        $rows[] = [
                            $sourceId,
                            $lang,
                            trim($text),
                            $now,
                            $now,
                            StringHelper::UUID(),
                        ];
                    }
                }

                if (!empty($rows)) {
                    $db->createCommand()->batchInsert(
                        '{{%tka_messages}}',
                        ['id', 'language', 'translation', 'dateCreated', 'dateUpdated', 'uid'],
                        $rows
                    )->execute();
                }
            }

            $transaction->commit();

            if ($compile && Plugin::getInstance()->getSettings()->compileToFiles) {
                Plugin::getInstance()->compiler->compile($category);
            }

            $this->trigger(self::EVENT_AFTER_ADD_MESSAGE, new Event());

            return $sourceId;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            Craft::error("Failed to add message: " . $e->getMessage(), __METHOD__);
            return null;
        }
    }

    /**
     * Batch save translations using bulk upserts inside a database transaction.
     *
     * @param array<int|string, array<string, ?string>> $translations [sourceId => [language => text]]
     */
    public function saveBatch(array $translations, ?string $category = null, bool $compile = true): bool
    {
        if (empty($translations)) {
            return true;
        }

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();
        $now = Db::prepareDateForDb(new \DateTime());

        try {
            foreach ($translations as $sourceId => $locales) {
                $sourceId = (int)$sourceId;
                if ($sourceId <= 0) {
                    continue;
                }

                foreach ($locales as $language => $text) {
                    $cleanText = ($text !== null && trim((string)$text) !== '') ? (string)$text : null;
                    $uid = StringHelper::UUID();

                    $db->createCommand()->upsert(
                        '{{%tka_messages}}',
                        [
                            'id' => $sourceId,
                            'language' => $language,
                            'translation' => $cleanText,
                            'dateCreated' => $now,
                            'dateUpdated' => $now,
                            'uid' => $uid,
                        ],
                        [
                            'translation' => $cleanText,
                            'dateUpdated' => $now,
                        ]
                    )->execute();
                }
            }

            $transaction->commit();

            if ($compile && Plugin::getInstance()->getSettings()->compileToFiles) {
                Plugin::getInstance()->compiler->compile($category);
            }

            $this->trigger(self::EVENT_AFTER_SAVE_TRANSLATIONS, new Event());

            return true;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            Craft::error("Failed to batch save translations: " . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    /**
     * Delete multiple source messages in a single query.
     *
     * @param int[] $sourceIds
     */
    public function deleteBatch(array $sourceIds, ?string $category = null, bool $compile = true): bool
    {
        if (empty($sourceIds)) {
            return true;
        }

        $db = Craft::$app->getDb();
        try {
            $db->createCommand()->delete(
                '{{%tka_source_messages}}',
                ['id' => $sourceIds]
            )->execute();

            if ($compile && Plugin::getInstance()->getSettings()->compileToFiles) {
                Plugin::getInstance()->compiler->compile($category);
            }

            $this->trigger(self::EVENT_AFTER_DELETE_MESSAGES, new Event());

            return true;
        } catch (\Throwable $e) {
            Craft::error("Failed to batch delete messages: " . $e->getMessage(), __METHOD__);
            return false;
        }
    }
}
