<?php

declare(strict_types=1);

namespace thekitchenagency\translations\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset as CraftCpAsset;

/**
 * Control panel asset bundle for TKA Translations.
 */
class CpAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__;

        $this->depends = [
            CraftCpAsset::class,
        ];

        $this->css = [
            'css/tka-translations.css',
        ];

        $this->js = [
            'js/tka-translations.js',
        ];

        parent::init();
    }
}
