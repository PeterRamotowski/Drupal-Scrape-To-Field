<?php

namespace Drupal\scrape_to_field\Exception;

/**
 * Indicates that another worker is already scraping the requested node.
 */
final class ScrapeLockUnavailableException extends \RuntimeException {

  /**
   * Constructs a ScrapeLockUnavailableException object.
   */
  public function __construct() {
    parent::__construct('The node is already being processed.');
  }

}
