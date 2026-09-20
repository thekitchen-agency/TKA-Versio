<?php

declare(strict_types=1);

namespace thekitchenagency\translations\jobs;

use Craft;
use craft\queue\BaseJob;
use thekitchenagency\translations\Plugin;

/**
 * Queue job to scan templates in the background.
 */
class ScanTemplatesJob extends BaseJob
{
    public function execute($queue): void
    {
        $this->setProgress($queue, 0.1, Craft::t('tka-translations', 'Scanning templates for translation keys...'));

        $results = Plugin::getInstance()->scanner->scanTemplates();

        $this->setProgress(
            $queue,
            1.0,
            Craft::t(
                'tka-translations',
                'Scanned {files} files, discovered {found} keys ({new} new).',
                [
                    'files' => $results['totalFiles'],
                    'found' => $results['totalDiscovered'],
                    'new' => $results['newAdded'],
                ]
            )
        );
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('tka-translations', 'Scanning templates for translations');
    }
}
