<?php

namespace Drupal\cgov_migration\Services\EventSubscriber;

use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\Event\MigrateIdMapMessageEvent;
use Drupal\migrate\Event\MigratePostRowSaveEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Class PostRowSaveSubscriber.
 *
 * @package Drupal\cgov_migration
 */
class PostRowSaveSubscriber implements EventSubscriberInterface {

  /**
   * Get subscribed events.
   *
   * @inheritdoc
   */
  public static function getSubscribedEvents() {
    $events[MigrateEvents::POST_ROW_SAVE][] = ['onPostRowSave'];
    $events[MigrateEvents::IDMAP_MESSAGE][] = ['idmapMessage'];
    return $events;
  }

  /**
   * Dispatched just after the import of a given row within a migration.
   */
  public function onPostRowSave(MigratePostRowSaveEvent $event) {
    // Migration object being imported.
    // $migration = $event->getMigration();
    // Row object containing the specific item just imported.
    $row = $event->getRow();
    echo($row->getDestinationProperty('id') . PHP_EOL);

    // The unique destination ID, as an array (accomodating multi-column keys),
    // of the item just imported.
    // $destination_id_values = $event->getDestinationIdValues();
  }

  /**
   * Dispatched just after the import of a given row within a migration.
   */
  public function idmapMessage(MigrateIdMapMessageEvent $event) {
    // Migration object being imported.
    // $migration = $event->getMigration();
    // Row object containing the specific item just imported.
    // $row = $event->getRow();
    echo('In ID Map' . print_r($event . PHP_EOL));
  }

}
