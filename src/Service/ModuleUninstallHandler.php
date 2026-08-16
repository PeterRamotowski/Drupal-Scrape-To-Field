<?php

namespace Drupal\scrape_to_field\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityDefinitionUpdateManagerInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\State\StateInterface;

/**
 * Handles pre-uninstall cleanup of module data.
 *
 * Extracted from the procedural scrape_to_field_module_preuninstall() hook
 * to enable dependency injection and unit testing.
 */
final class ModuleUninstallHandler {

  /**
   * Constructs a ModuleUninstallHandler object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityDefinitionUpdateManagerInterface $entityDefinitionUpdateManager
   *   The entity definition update manager.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $keyValueFactory
   *   The key-value factory.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly EntityDefinitionUpdateManagerInterface $entityDefinitionUpdateManager,
    private readonly StateInterface $state,
    private readonly KeyValueFactoryInterface $keyValueFactory,
  ) {}

  /**
   * Runs all pre-uninstall cleanup tasks.
   *
   * Clears field data, removes the field storage definition, and purges
   * orphaned deleted-field configuration from Drupal State.
   */
  public function preUninstall(): void {
    $this->clearFieldData();
    $this->uninstallFieldStorageDefinition();
    $this->cleanupDeletedFieldConfigs();
  }

  /**
   * Nullifies any stored field data in the node tables.
   */
  private function clearFieldData(): void {
    $schema = $this->database->schema();

    if ($schema->fieldExists('node_field_data', 'field_scraper_config')) {
      $this->database->update('node_field_data')
        ->fields(['field_scraper_config' => NULL])
        ->isNotNull('field_scraper_config')
        ->execute();
    }

    if ($schema->fieldExists('node_field_revision', 'field_scraper_config')) {
      $this->database->update('node_field_revision')
        ->fields(['field_scraper_config' => NULL])
        ->isNotNull('field_scraper_config')
        ->execute();
    }
  }

  /**
   * Removes the field_scraper_config storage definition via the entity API.
   */
  private function uninstallFieldStorageDefinition(): void {
    $schema = $this->database->schema();
    $columns_exist = $schema->fieldExists('node_field_data', 'field_scraper_config')
      || $schema->fieldExists('node_field_revision', 'field_scraper_config');

    $field_definition = $this->entityDefinitionUpdateManager
      ->getFieldStorageDefinition('field_scraper_config', 'node');

    if ($field_definition instanceof FieldStorageDefinitionInterface && $columns_exist) {
      try {
        $this->entityDefinitionUpdateManager
          ->uninstallFieldStorageDefinition($field_definition);
      }
      catch (\Exception) {
        $this->manualFieldCleanup($columns_exist);
      }
    }
    else {
      $this->manualFieldCleanup($columns_exist);
    }
  }

  /**
   * Falls back to manual schema and key-value cleanup on API failure.
   *
   * @param bool $columns_exist
   *   Whether the database columns still exist.
   */
  private function manualFieldCleanup(bool $columns_exist): void {
    $schema = $this->database->schema();

    if ($columns_exist) {
      if ($schema->fieldExists('node_field_data', 'field_scraper_config')) {
        $schema->dropField('node_field_data', 'field_scraper_config');
      }
      if ($schema->fieldExists('node_field_revision', 'field_scraper_config')) {
        $schema->dropField('node_field_revision', 'field_scraper_config');
      }
    }

    $key_value = $this->keyValueFactory->get('entity.definitions.installed');
    $definitions = $key_value->get('node.field_storage_definitions', []);
    if (isset($definitions['field_scraper_config'])) {
      unset($definitions['field_scraper_config']);
      $key_value->set('node.field_storage_definitions', $definitions);
    }

    $schema_kv = $this->keyValueFactory->get('entity.storage_schema.sql');
    $schema_kv->delete('node.field_schema_data.field_scraper_config');
  }

  /**
   * Removes orphaned deleted-field configs referencing field_scraper_config.
   */
  private function cleanupDeletedFieldConfigs(): void {
    $deleted = $this->state->get('field.field.deleted') ?? [];
    $cleaned = [];

    foreach ($deleted as $uuid => $field) {
      if ($this->isScraperConfigField($field)) {
        continue;
      }
      $cleaned[$uuid] = $field;
    }

    if (count($cleaned) !== count($deleted)) {
      $this->state->set('field.field.deleted', $cleaned);
    }
  }

  /**
   * Checks whether a deleted-field entry belongs to field_scraper_config.
   *
   * @param mixed $field
   *   The field entry from state.
   *
   * @return bool
   *   TRUE when the entry should be removed.
   */
  private function isScraperConfigField(mixed $field): bool {
    if (!is_object($field)) {
      return FALSE;
    }

    if (method_exists($field, 'getName') && $field->getName() === 'field_scraper_config') {
      return TRUE;
    }

    if ($field instanceof BaseFieldDefinition) {
      $def = $field->toArray();
      return isset($def['field_name']) && $def['field_name'] === 'field_scraper_config';
    }

    return FALSE;
  }

}
