<p align="center">
  <img src="icon.svg" width="120" height="120" alt="TKA Versio Icon" />
</p>

<h1 align="center">TKA Versio for Craft CMS 5</h1>

<p align="center">
  <strong>High-performance, AI-powered translation and localization management for Craft CMS 5.</strong>
</p>

---

## 🌟 Overview

**TKA Versio** is a modern localization and translation suite designed specifically for Craft CMS 5. It bridges the gap between template static messages and multi-site entry content translation, featuring high-speed OPcache compilation, multi-provider AI translation (DeepL, OpenAI, Google Gemini), an AI cost dashboard, and an intuitive side-by-side entry translator.

---

## ✨ Key Features

### 🚀 1. Zero-Query Frontend Performance (OPcache Compilation)
- Automatically compiles database translations into static, native PHP array files (`translations/{locale}/{category}.php`).
- Leverages PHP's built-in **OPcache** for microsecond lookups with **zero runtime database queries**.
- Fallback hash-indexed lookups eliminate slow table scans.

### 📝 2. Side-by-Side Multi-Site Entry Translator
- Translate entries directly side-by-side with the primary source site.
- **Deep Field Traversal:** Supports native Craft text fields, Plain Text, Redactor, CKEditor, and nested Craft 5 Matrix blocks.
- **Clutter-Free View:** One-click toggle to hide empty source fields so translators only see relevant content.
- **Accurate Progress Calculation:** Real-time completion badges (`Translated`, `Partial (x/total)`, `Untranslated`).
- **Single-Click & Batch AI Translation:** Translate individual entry fields or entire entries in seconds.

### 🤖 3. Multi-Provider AI Translation Engine
- Supports industry-leading AI translation providers:
  - **Google Gemini** (`gemini-flash-latest`, `gemini-1.5-pro`)
  - **DeepL API** (Free & Pro API support)
  - **OpenAI** (`gpt-4o-mini`, `gpt-4o`, `gpt-3.5-turbo`)
- **Resilient Batch Processing:** Built-in rate-limit handling with exponential backoff (HTTP 429 / 503 retry).
- Real-time progress bar with live token, character, and cost estimations.

### 📊 4. Real-Time AI Cost & Usage Dashboard
- Live dashboard integrated right into the Control Panel Settings.
- Tracks character counts, token usage, estimated costs per provider, and total translation operations.
- Inspect recent translation activity and query logs.

### 🔍 5. Twig 3 AST Template Scanner
- Scans template files for `|t` filters and `Craft::t()` invocations.
- Configurable scan paths (e.g. `@templates`, custom module directories) with regex exclusion patterns.
- Automated extraction of missing translation keys directly into the database.

### 📦 6. Import & Export
- Bi-directional synchronization for CSV, JSON, and PHP translation files.
- Perfect for external translator handoffs and translation agency workflows.

### ⚡ 7. Complete CLI Suite
- Automate translation compilation, template scanning, and file imports in CI/CD deployment pipelines.

---

## 📋 Requirements

- **PHP:** >= 8.2
- **Craft CMS:** >= 5.0.0

---

## 💻 Installation

1. Add the repository to your project's `composer.json` or install via Composer:

```bash
composer require thekitchen-agency/craft-tka-translations
```

2. Install the plugin in Craft CMS:

```bash
php craft plugin/install tka-translations
```

---

## ⚙️ Configuration & Settings

Configure settings via the Craft Control Panel (**TKA Versio → Settings**) or by creating a `config/tka-translations.php` config file:

```php
<?php

use craft\helpers\App;

return [
    'pluginName' => 'TKA Versio',
    'compileToFiles' => true,
    'compilationPath' => '@translations',
    'categories' => ['site', 'app'],
    'scanPaths' => ['@templates'],
    'excludedMessages' => ['*debug*'],
    
    // AI Translation Settings
    'defaultAiProvider' => 'gemini', // 'gemini', 'deepl', 'openai'
    'geminiApiKey' => App::env('GEMINI_API_KEY'),
    'geminiModel' => 'gemini-flash-latest',
    'deeplApiKey' => App::env('DEEPL_API_KEY'),
    'deeplApiType' => 'free',
    'openaiApiKey' => App::env('OPENAI_API_KEY'),
    'openaiModel' => 'gpt-4o-mini',
    
    // Multi-site Section Scope (leave empty to include all)
    'translatableSections' => [],
];
```

---

## 🛠️ CLI Commands

TKA Versio provides console commands for headless operations:

```bash
# Compile all database translations to static PHP files
./craft tka-translations/utilities/compile

# Scan templates for missing translation keys
./craft tka-translations/utilities/scan

# Import existing static PHP translation files into the database
./craft tka-translations/utilities/import-php
```

---

## 📄 License

Proprietary / MIT License © [The Kitchen Agency](https://thekitchen.agency).
