<?php

declare(strict_types=1);

/**
 * Import mode for CsvHelper::import().
 *
 * Choose the mode that matches the data contract for your target table(s):
 *
 *   InsertNew  — Add rows whose natural key is not yet present; skip existing.
 *                Safe for additive migrations (e.g. adding new translation keys).
 *                Cannot cause data loss.
 *
 *   Upsert     — Insert new rows and update existing rows. Never deletes.
 *                The default for most importers. Safe to run repeatedly.
 *                Use this for i18n translations, user data, config tables.
 *
 *   Sync       — Insert new, update existing, and DELETE rows whose natural
 *                key is absent from the CSV. The CSV becomes the full source
 *                of truth after import.
 *
 *                REQUIRED for nested-set tables (resource_tree, adminmenu_tree,
 *                mainmenu_tree, etc.). Partial imports leave lft/rgt values
 *                out of sync, which corrupts the tree. A nested-set iCsvMap
 *                MUST declare Sync as its only supported mode and rebuild the
 *                nested-set indices inside deleteAbsentRows().
 *
 *                Also useful for translation cleanup: importing with Sync
 *                removes keys that no longer exist in the codebase.
 */
enum CsvImportMode: string
{
	case InsertNew = 'insert_new';
	case Upsert    = 'upsert';
	case Sync      = 'sync';
}
