<?php

namespace Drupal\scrape_to_field\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Url;

/**
 * Hook implementations for scrape_to_field module.
 */
class Hooks {

  /**
   * Implements hook_entity_base_field_info().
   */
  #[Hook('entity_base_field_info')]
  public function scraperConfigEntityBaseFieldInfo(EntityTypeInterface $entity_type) {
    $fields = [];
    
    if ($entity_type->id() == 'node') {
      $scraper_config_field_definition = \Drupal::entityDefinitionUpdateManager()->getFieldStorageDefinition('field_scraper_config', 'node');
      
      if ($scraper_config_field_definition instanceof FieldStorageDefinitionInterface) {
        /** @var \Drupal\Core\Field\BaseFieldDefinition $scraper_config_field_definition */
        $scraper_config_field_definition->setDisplayOptions('form', [
          'region' => 'hidden',
        ])->setDisplayOptions('view', [
          'region' => 'hidden',
        ]);
        $fields['field_scraper_config'] = $scraper_config_field_definition;
      }
    }
    
    return $fields;
  }

  /**
   * Implements hook_cron().
   */
  #[Hook('cron')]
  public function scraperCron() {
    $config = \Drupal::config('scrape_to_field.settings');

    if (!$config->get('enable_cron')) {
      return;
    }

    /** @var \Drupal\scrape_to_field\Service\QueueManager $queueManager */
    $queueManager = \Drupal::service('scrape_to_field.queue');
    $queued = $queueManager->queueScrapingJobsWithFrequency();

    /** @var \Drupal\scrape_to_field\Service\ScraperActivityLogger $scraperLogger */
    $scraperLogger = \Drupal::service('scrape_to_field.activity_logger');
    $scraperLogger->logQueueActivity($queued);
  }

  /**
   * Implements hook_entity_operation().
   */
  #[Hook('entity_operation')]
  public function scraperEntityOperation(EntityInterface $entity) {
    $operations = [];

    if ($entity->getEntityTypeId() === 'node') {
      $operations['scraper_config'] = [
        'title' => t('Scraper configuration'),
        'url' => Url::fromRoute('scrape_to_field.node_scraper_config', [
          'node' => $entity->id(),
        ]),
        'weight' => 50,
      ];
    }

    return $operations;
  }

}