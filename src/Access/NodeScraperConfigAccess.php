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
    // Global admin permission allows access to any node.
    if ($account->hasPermission('configure any node scrape to field')) {
      return AccessResult::allowedIfHasPermission($account, 'configure any node scrape to field')
        ->addCacheContexts(['user.permissions'])
        ->addCacheTags(['node:' . $node->id()]);
    }

    // Check if user can configure own nodes and owns this node.
    if ($account->hasPermission('configure own node scrape to field')) {
      $is_owner = $node->getOwnerId() == $account->id();
      return AccessResult::allowedIf($is_owner)
        ->addCacheContexts(['user.permissions', 'user'])
        ->addCacheTags(['node:' . $node->id()]);
    }

    // No access granted.
    return AccessResult::forbidden()
      ->addCacheContexts(['user.permissions'])
      ->addCacheTags(['node:' . $node->id()]);
  }

}
