<?php

namespace Drupal\scrape_to_field\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\scrape_to_field\Service\QueueManager;
use Drupal\scrape_to_field\Service\ScraperActivityLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Hook implementations for scrape_to_field module.
 */
final class Hooks {

  use StringTranslationTrait;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'scrape_to_field.activity_logger')]
    protected ScraperActivityLogger $scraperLogger,
    #[Autowire(service: 'scrape_to_field.queue')]
    protected QueueManager $queueManager,
    #[Autowire(service: 'keyvalue')]
    protected KeyValueFactoryInterface $keyValueFactory,
  ) {}

  /**
   * Implements hook_entity_base_field_info().
   */
  #[Hook('entity_base_field_info')]
  public function scraperConfigEntityBaseFieldInfo(EntityTypeInterface $entity_type) {
    $fields = [];

    if ($entity_type->id() !== 'node') {
      return $fields;
    }

    // Only provide the field if it's actually installed in the database.
    // This prevents errors during module install/uninstall.
    $key_value = $this->keyValueFactory->get('entity.definitions.installed');
    $installed_definitions = $key_value->get('node.field_storage_definitions', []);

    if (!isset($installed_definitions['field_scraper_config'])) {
      return $fields;
    }

    $fields['field_scraper_config'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Scrape to field Configuration'))
      ->setDescription(new TranslatableMarkup('Internal field to store scrape to field configuration data.'))
      ->setDefaultValue('')
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayOptions('form', [
        'region' => 'hidden',
      ])
      ->setDisplayOptions('view', [
        'region' => 'hidden',
      ]);

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

    if ($entity instanceof NodeInterface) {
      $url = Url::fromRoute('scrape_to_field.node_scraper_config', [
        'node' => $entity->id(),
      ]);

      if (!$url->access()) {
        return $operations;
      }

      $operations['scraper_config'] = [
        'title' => $this->t('Scraper configuration'),
        'url' => $url,
        'weight' => 50,
      ];
    }

    return $operations;
  }

}
