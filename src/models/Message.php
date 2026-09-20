<?php

declare(strict_types=1);

namespace thekitchenagency\translations\models;

use craft\base\Model;

/**
 * Message model representing a localized translation string.
 */
class Message extends Model
{
    public ?int $id = null;
    public string $language = '';
    public ?string $translation = null;
    public ?string $dateCreated = null;
    public ?string $dateUpdated = null;
    public ?string $uid = null;

    public function rules(): array
    {
        return [
            [['id', 'language'], 'required'],
            [['id'], 'integer'],
            [['language'], 'string', 'max' => 16],
            [['translation'], 'string'],
        ];
    }
}
