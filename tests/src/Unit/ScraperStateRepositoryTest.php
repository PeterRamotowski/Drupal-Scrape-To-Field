<?php

namespace Drupal\Tests\scrape_to_field\Unit;

use Drupal\scrape_to_field\Repository\ScraperStateRepository;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Delete;
use Drupal\Core\State\StateInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests the ScraperStateRepository class.
 */
class ScraperStateRepositoryTest extends TestCase {

  /**
   * The repository under test.
   */
  protected ScraperStateRepository $repository;

  /**
   * Mock state service.
   *
   * @var \Drupal\Core\State\StateInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected StateInterface|MockObject $state;

  /**
   * Mock database connection.
   *
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected Connection|MockObject $database;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->state = $this->createMock(StateInterface::class);
    $this->database = $this->createMock(Connection::class);
    $this->repository = new ScraperStateRepository($this->state, $this->database);
  }

  /**
   * Tests getLastScrapeTime returns 0 when never scraped.
   */
  public function testGetLastScrapeTimeDefaultsToZero(): void {
    $this->state
      ->method('get')
      ->with('scrape_to_field.last_scrape.1.field_body', 0)
      ->willReturn(0);

    $result = $this->repository->getLastScrapeTime(1, 'field_body');

    $this->assertEquals(0, $result);
  }

  /**
   * Tests setLastScrapeTime calls state->set with the correct key.
   */
  public function testSetLastScrapeTimePersistsCorrectKey(): void {
    $this->state
      ->expects($this->once())
      ->method('set')
      ->with('scrape_to_field.last_scrape.42.field_title', 1700000000);

    $this->repository->setLastScrapeTime(42, 'field_title', 1700000000);
  }

  /**
   * Tests getLastQueuedTime returns 0 when never queued.
   */
  public function testGetLastQueuedTimeDefaultsToZero(): void {
    $this->state
      ->method('get')
      ->with('scrape_to_field.queued.1.field_body', 0)
      ->willReturn(0);

    $result = $this->repository->getLastQueuedTime(1, 'field_body');

    $this->assertEquals(0, $result);
  }

  /**
   * Tests setLastQueuedTime calls state->set with the correct key.
   */
  public function testSetLastQueuedTimePersistsCorrectKey(): void {
    $this->state
      ->expects($this->once())
      ->method('set')
      ->with('scrape_to_field.queued.5.field_summary', 1700000001);

    $this->repository->setLastQueuedTime(5, 'field_summary', 1700000001);
  }

  /**
   * Tests clearQueuedState calls state->delete with the correct key.
   */
  public function testClearQueuedStateDeletesCorrectKey(): void {
    $this->state
      ->expects($this->once())
      ->method('delete')
      ->with('scrape_to_field.queued.10.field_body');

    $this->repository->clearQueuedState(10, 'field_body');
  }

  /**
   * Tests isStale returns true when last scrape >= queued timestamp.
   */
  public function testIsStaleReturnsTrueWhenScraped(): void {
    $this->state
      ->method('get')
      ->with('scrape_to_field.last_scrape.1.field_body', 0)
      ->willReturn(1700000100);

    $result = $this->repository->isStale(1, 'field_body', 1700000000);

    $this->assertTrue($result);
  }

  /**
   * Tests isStale returns false when last scrape < queued timestamp.
   */
  public function testIsStaleReturnsFalseWhenNotScrapedYet(): void {
    $this->state
      ->method('get')
      ->with('scrape_to_field.last_scrape.1.field_body', 0)
      ->willReturn(1699999999);

    $result = $this->repository->isStale(1, 'field_body', 1700000000);

    $this->assertFalse($result);
  }

  /**
   * Tests deleteAllForModule executes the correct DELETE query.
   */
  public function testDeleteAllForModuleExecutesLikeQuery(): void {
    $deleteQuery = $this->createMock(Delete::class);
    $deleteQuery->method('condition')->willReturnSelf();
    $deleteQuery->expects($this->once())->method('execute');

    $this->database
      ->expects($this->once())
      ->method('delete')
      ->with('key_value')
      ->willReturn($deleteQuery);

    $this->repository->deleteAllForModule();
  }

}
