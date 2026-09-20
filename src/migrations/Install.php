<?php

declare(strict_types=1);

namespace thekitchenagency\translations\migrations;

use craft\db\Migration;

/**
 * Install migration for TKA Translations.
 */
class Install extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $tableOptions = null;
        if ($this->db->getIsMysql()) {
            $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci ENGINE=InnoDB';
        }

        // 1. Source Messages table
        if (!$this->db->tableExists('{{%tka_source_messages}}')) {
            $this->createTable(
                '{{%tka_source_messages}}',
                [
                    'id' => $this->primaryKey(),
                    'category' => $this->string(255)->notNull()->defaultValue('site'),
                    'message' => $this->text()->notNull(),
                    'messageHash' => $this->char(32)->notNull(),
                    'dateCreated' => $this->dateTime()->notNull(),
                    'dateUpdated' => $this->dateTime()->notNull(),
                    'uid' => $this->uid(),
                ],
                $tableOptions
            );

            $this->createIndex(
                'idx_tka_source_message_hash',
                '{{%tka_source_messages}}',
                ['category', 'messageHash'],
                true
            );

            $this->createIndex(
                'idx_tka_source_message_category',
                '{{%tka_source_messages}}',
                'category'
            );
        }

        // 2. Translations table
        if (!$this->db->tableExists('{{%tka_messages}}')) {
            $this->createTable(
                '{{%tka_messages}}',
                [
                    'id' => $this->integer()->notNull(),
                    'language' => $this->string(16)->notNull(),
                    'translation' => $this->text()->null(),
                    'dateCreated' => $this->dateTime()->notNull(),
                    'dateUpdated' => $this->dateTime()->notNull(),
                    'uid' => $this->uid(),
                ],
                $tableOptions
            );

            $this->addPrimaryKey('pk_tka_messages', '{{%tka_messages}}', ['id', 'language']);

            $this->addForeignKey(
                'fk_tka_messages_source',
                '{{%tka_messages}}',
                'id',
                '{{%tka_source_messages}}',
                'id',
                'CASCADE',
                'CASCADE'
            );

            $this->createIndex(
                'idx_tka_message_language',
                '{{%tka_messages}}',
                'language'
            );
        }

        // 3. AI Usage Tracking table
        if (!$this->db->tableExists('{{%tka_ai_usage}}')) {
            $this->createTable(
                '{{%tka_ai_usage}}',
                [
                    'id' => $this->primaryKey(),
                    'provider' => $this->string(32)->notNull(),
                    'model' => $this->string(64)->notNull(),
                    'sourceLocale' => $this->string(16)->null(),
                    'targetLocale' => $this->string(16)->notNull(),
                    'charactersCount' => $this->integer()->notNull()->defaultValue(0),
                    'inputTokens' => $this->integer()->notNull()->defaultValue(0),
                    'outputTokens' => $this->integer()->notNull()->defaultValue(0),
                    'estimatedCostUsd' => $this->decimal(10, 6)->notNull()->defaultValue(0.000000),
                    'dateCreated' => $this->dateTime()->notNull(),
                    'uid' => $this->uid(),
                ],
                $tableOptions
            );

            $this->createIndex(
                'idx_tka_ai_usage_provider',
                '{{%tka_ai_usage}}',
                'provider'
            );

            $this->createIndex(
                'idx_tka_ai_usage_date',
                '{{%tka_ai_usage}}',
                'dateCreated'
            );
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        if ($this->db->tableExists('{{%tka_ai_usage}}')) {
            $this->dropTableIfExists('{{%tka_ai_usage}}');
        }

        if ($this->db->tableExists('{{%tka_messages}}')) {
            $this->dropTableIfExists('{{%tka_messages}}');
        }

        if ($this->db->tableExists('{{%tka_source_messages}}')) {
            $this->dropTableIfExists('{{%tka_source_messages}}');
        }

        return true;
    }
}
