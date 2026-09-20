<?php

declare(strict_types=1);

namespace thekitchenagency\translations\jobs;

use Craft;
use craft\queue\BaseJob;
use thekitchenagency\translations\Plugin;

/**
 * Queue job to import existing PHP translation files into the database.
 */
class ImportTranslationsJob extends BaseJob
{
    public function execute($queue): void
    {
        $this->setProgress($queue, 0.2, Craft::t('tka-translations', 'Importing translations from PHP files...'));

        $results = Plugin::getInstance()->importExport->importExistingPhpFiles();

        $this->setProgress(
            $queue,
            1.0,
            Craft::t(
                'tka-translations',
                'Imported {imported} of {read} translation strings.',
                [
                    'imported' => $results['totalImported'],
                    'read' => $results['totalRead'],
                ]
            )
        );
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('tka-translations', 'Importing PHP translation files');
    }
}
