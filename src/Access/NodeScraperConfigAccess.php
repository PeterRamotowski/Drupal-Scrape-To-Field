<?php

namespace Drupal\scrape_to_field\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\Routing\Route;

/**
 * Checks access for configuring node scrape to field settings.
 */
class NodeScraperConfigAccess implements AccessInterface {

  /**
   * A custom access check.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Run access checks for this account.
   * @param \Symfony\Component\Routing\Route $route
   *   The route to check against.
   * @param \Drupal\node\NodeInterface $node
   *   The node being configured.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(AccountInterface $account, Route $route, NodeInterface $node) {
    $update_access = $node->access('update', $account, TRUE);

    $any_access = AccessResult::allowedIfHasPermission($account, 'configure any node scrape to field');
    $own_access = AccessResult::allowedIfHasPermission($account, 'configure own node scrape to field')
      ->andIf(AccessResult::allowedIf((int) $node->getOwnerId() === (int) $account->id()))
      ->cachePerUser();

    return $any_access
      ->orIf($own_access)
      ->andIf($update_access)
      ->addCacheableDependency($node)
      ->cachePerPermissions();
  }

}
