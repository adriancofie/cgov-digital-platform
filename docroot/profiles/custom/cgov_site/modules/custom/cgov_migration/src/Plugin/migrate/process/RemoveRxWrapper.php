<?php

namespace Drupal\cgov_migration\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;

/**
 * Remove the rxbodyfield wrapper..
 *
 * @MigrateProcessPlugin(
 *   id = "remove_rx_wrapper"
 * )
 */
class RemoveRxWrapper extends CgovPluginBase {


  protected $migLog;
  protected $doc;

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {

    // Exit early if the field not set.
    if (!isset($value)) {
      return NULL;
    }

    echo($value . PHP_EOL . '!!!!!!OGVALUE' . PHP_EOL . PHP_EOL);
    $pid = $this->getPercID($row);

    // Load the incoming HTML.
    $this->doc->html($value);

    // Find elements with the class.
    $elements = $this->doc->find('.rxbodyfield');
    $size = $elements->count();

    // Log an throw an error if there is more than one.
    if ($size > 1) {
      $message = 'The incoming item has ' . $size . ' .rxbodyfield items.';
      echo('The incoming item has size: ' . $size);
      $this->migLog->logMessage($pid, $message, E_ERROR, 'RXBODY');
      throw new MigrateSkipRowException();
    }
    elseif ($size == 1) {
      // Retrieve the content.
      $value = $elements->first()->html();
    }
    return $value;
  }

}
