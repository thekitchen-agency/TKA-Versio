<?php

declare(strict_types=1);

namespace thekitchenagency\translations\jobs;

use Craft;
use craft\queue\BaseJob;
use thekitchenagency\translations\Plugin;

/**
 * Queue job to compile translations into static PHP files.
 */
class CompileTranslationsJob extends BaseJob
{
    public ?string $category = null;

    public function execute($queue): void
    {
        $this->setProgress($queue, 0.2, Craft::t('tka-translations', 'Compiling translation files...'));

        $stats = Plugin::getInstance()->compiler->compile($this->category);

        $this->setProgress(
            $queue,
            1.0,
            Craft::t('tka-translations', 'Compiled {count} translation dictionaries.', ['count' => count($stats)])
        );
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('tka-translations', 'Compiling static translation files');
    }
}
