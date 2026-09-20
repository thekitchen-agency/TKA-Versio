<?php

declare(strict_types=1);

namespace thekitchenagency\translations\models;

use craft\base\Model;
use DateTime;

/**
 * SourceMessage model representing a translation key.
 */
class SourceMessage extends Model
{
    public ?int $id = null;
    public string $category = 'site';
    public string $message = '';
    public ?string $messageHash = null;
    public ?string $dateCreated = null;
    public ?string $dateUpdated = null;
    public ?string $uid = null;

    /**
     * @var array<string, ?string> [languageId => translation]
     */
    public array $translations = [];

    public function rules(): array
    {
        return [
            [['category', 'message'], 'required'],
            [['category', 'message', 'messageHash'], 'string'],
            [['id'], 'integer'],
            [['translations'], 'safe'],
        ];
    }

    /**
     * Generate a deterministic MD5 hash for (category::message).
     */
    public static function hash(string $category, string $message): string
    {
        return md5($category . '::' . $message);
    }

    public function beforeValidate(): bool
    {
        if (empty($this->messageHash) && !empty($this->message)) {
            $this->messageHash = self::hash($this->category, $this->message);
        }

        return parent::beforeValidate();
    }
}
