<?php

namespace Drupal\scrape_to_field\Repository;

use Drupal\node\NodeInterface;
use Drupal\scrape_to_field\DTO\NodeScraperConfigDto;
use Drupal\scrape_to_field\Service\ScraperActivityLogger;

/**
 * Persists and retrieves scraper configuration from the node field.
 *
 * Provides a single point of access for reading and writing the
 * field_scraper_config base field, eliminating duplicated JSON parsing
 * and DTO hydration across services and forms.
 */
class NodeScraperConfigRepository implements NodeScraperConfigRepositoryInterface {

  /**
   * Constructs a NodeScraperConfigRepository object.
   *
   * @param \Drupal\scrape_to_field\Service\ScraperActivityLogger $scraperLogger
   *   The activity logger, used to record malformed configuration errors.
   */
  public function __construct(
    protected ScraperActivityLogger $scraperLogger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function hasConfig(NodeInterface $node): bool {
    if (!$node->hasField('field_scraper_config')) {
      return FALSE;
    }

    return !$node->get('field_scraper_config')->isEmpty();
  }

  /**
   * {@inheritdoc}
   */
  public function getConfig(NodeInterface $node): NodeScraperConfigDto {
    if (!$node->hasField('field_scraper_config')) {
      return NodeScraperConfigDto::disabled();
    }

    $config_field = $node->get('field_scraper_config');
    if ($config_field->isEmpty()) {
      return NodeScraperConfigDto::disabled();
    }

    $config_value = $config_field->first()->getValue();
    $json = $config_value['value'] ?? '[]';

    try {
      return NodeScraperConfigDto::fromJson($json);
    }
    catch (\InvalidArgumentException | \TypeError $exception) {
      $this->scraperLogger->logInvalidConfiguration(
        (int) $node->id(),
        $exception->getMessage(),
      );
      return NodeScraperConfigDto::disabled();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function saveConfig(NodeInterface $node, NodeScraperConfigDto $config): void {
    $node->set('field_scraper_config', $config->toJson());
    $node->save();
  }

  /**
   * {@inheritdoc}
   */
  public function clearConfig(NodeInterface $node): void {
    $node->set('field_scraper_config', '');
    $node->save();
  }

}
