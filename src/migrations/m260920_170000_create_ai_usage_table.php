<?php

declare(strict_types=1);

namespace thekitchenagency\translations\migrations;

use craft\db\Migration;

/**
 * Migration to create AI usage tracking table.
 */
class m260920_170000_create_ai_usage_table extends Migration
{
    public function safeUp(): bool
    {
        $tableOptions = null;
        if ($this->db->getIsMysql()) {
            $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci ENGINE=InnoDB';
        }

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

    public function safeDown(): bool
    {
        if ($this->db->tableExists('{{%tka_ai_usage}}')) {
            $this->dropTableIfExists('{{%tka_ai_usage}}');
        }

        return true;
    }
}
