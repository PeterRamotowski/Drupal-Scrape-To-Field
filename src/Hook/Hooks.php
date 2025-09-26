<?php

namespace Drupal\scrape_to_field\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityDefinitionUpdateManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\scrape_to_field\Service\QueueManager;
use Drupal\scrape_to_field\Service\ScraperActivityLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Hook implementations for scrape_to_field module.
 */
class Hooks {

  use StringTranslationTrait;

  public function __construct(
    protected EntityDefinitionUpdateManagerInterface $entityDefinitionUpdateManager,
    protected ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'scrape_to_field.activity_logger')]
    protected ScraperActivityLogger $scraperLogger,
    #[Autowire(service: 'scrape_to_field.queue')]
    protected QueueManager $queueManager,
  ) {}

  /**
   * Implements hook_entity_base_field_info().
   */
  #[Hook('entity_base_field_info')]
  public function scraperConfigEntityBaseFieldInfo(EntityTypeInterface $entity_type) {
    $fields = [];

    if ($entity_type->id() == 'node') {
      $scraper_config_field_definition = $this->entityDefinitionUpdateManager->getFieldStorageDefinition('field_scraper_config', 'node');

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
    $config = $this->configFactory->get('scrape_to_field.settings');

    if (!$config->get('enable_cron')) {
      return;
    }

    $queued = $this->queueManager->queueScrapingJobsWithFrequency();
    $this->scraperLogger->logQueueActivity($queued);
  }

  /**
   * Implements hook_entity_operation().
   */
  #[Hook('entity_operation')]
  public function scraperEntityOperation(EntityInterface $entity) {
    $operations = [];

    if ($entity->getEntityTypeId() === 'node') {
      $operations['scraper_config'] = [
        'title' => $this->t('Scraper configuration'),
        'url' => Url::fromRoute('scrape_to_field.node_scraper_config', [
          'node' => $entity->id(),
        ]),
        'weight' => 50,
      ];
    }

    return $operations;
  }

}
