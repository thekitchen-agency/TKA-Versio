# Changelog - TKA Versio

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.2] - 2026-10-04

### Fixed
- **Source Site Overwrite Prevention**: Pass `propagate: false` when saving target entry and Matrix blocks so target site translations do not propagate back to and overwrite the source/canonical site.
- **Block Site Isolation**: Ensure `$targetBlock` element instance always matches `targetSiteId` before saving.

## [1.1.1] - 2026-10-03

### Fixed
- **Matrix Block Translations**: Explicitly persist nested Matrix block elements when saving target entries in Craft CMS 5, ensuring block translations are saved to the database.
- **Block Pairing Across Sites**: Match target Matrix blocks by element ID / canonical ID in addition to numerical index.
- **Validation & Error Reporting**: Added detailed error feedback if target entry or Matrix block validation fails during save.
- **Editor Input Handling**: Added change event listeners, fixed title/slug dirty state resolution, and added copy-to-target button for Matrix fields.
- **Translatable Status Indicator**: Visual indicator in comparison tree for fields that are not configured as translatable (`translationMethod: none`) in Craft CMS.

## [1.1.0] - 2026-09-20

### Added
- **Side-by-Side Entry Translator**: Full side-by-side editing interface for translating multi-site Craft entries.
- **Deep Field & Matrix Parsing**: Recursive translation support for native Craft fields, Matrix blocks, and nested entry structures.
- **Hide Empty Fields Toggle**: Instant interface cleanup to only show fields that have content in the primary source site.
- **Accurate Translation Progress Tracking**: Real-time computation of entry translation percentages (`Translated (100%)`, `Partial (x/total)`, `Untranslated`).
- **Multi-Provider AI Translation Engine**:
  - Google Gemini API (`gemini-flash-latest`, `gemini-1.5-pro`).
  - DeepL API (Free & Pro authentication).
  - OpenAI API (`gpt-4o-mini`, `gpt-4o`, `gpt-3.5-turbo`).
- **AI Cost & Usage Analytics Dashboard**: Live metrics tracking character count, token consumption, estimated costs, and recent AI translation requests.
- **Rate-Limit Resilience**: Automatic exponential backoff and retry mechanism for HTTP 429 and 503 responses.
- **Custom Scan Paths**: Configurable template and directory scanning with regex pattern exclusions.
- **Customizable Section Filtering**: Setting to select specific multi-site sections for translation management.
- **Brand Refresh**: Renamed plugin to **TKA Versio** and added custom Control Panel SVG mask and badge icons.

### Changed
- Improved batch translation speed and progress feedback.
- Updated database migrations to track detailed AI token and character usage.

---

## [1.0.0] - 2026-09-15

### Added
- Initial release of translation management for Craft CMS 5.
- Zero-query static PHP OPcache file compiler (`@translations/{locale}/{category}.php`).
- High-performance hash-indexed database schema with batch upserts.
- Server-side paginated Control Panel message editor.
- Twig 3 AST scanner for `|t` filters and `Craft::t()` calls.
- CSV, JSON, and PHP import/export functionality.
- Console CLI utilities (`tka-translations/utilities/*`).
