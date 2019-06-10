<?php

namespace Drupal\cgov_migration\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;

/**
 * Replace percussion links tags with drupal node links.
 *
 * @MigrateProcessPlugin(
 *   id = "replace_links"
 * )
 */
class ReplaceLinks extends CgovPluginBase {

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

    // Setup the document.
    $doc = $this->doc;
    $pid = $this->getPercID($row);
    $doc->html($value);

    // Loop through the anchor elements and search for ones with dependent ID's.
    // Replace those anchors with the equivalent drupal '/node/id' URL.
    // If a dependent ID belongs to a microsite, transorms to the static URL.
    $anchors = $doc->getElementsByTagName('a');
    foreach ($anchors as $anchor) {
      $sys_dependentid = $anchor->getAttribute('sys_dependentid');
      $content = $anchor->nodeValue;
      if (!empty($sys_dependentid)) {

        // LOG THE ENCOUNTERED ATTRIBUTES.
        if ($anchor->hasAttributes()) {
          foreach ($anchor->attributes as $attr) {
            $name = $attr->nodeName;
            $value = mb_strimwidth($attr->nodeValue, 0, 50);
            $this->migLog->logMessage($pid, "Attribute '$name' :: '$value'", E_WARNING, 'ATTRIBUTES:' . $sys_dependentid);
          }
        }

        $replacementElement = $this->createLinkitEmbed($sys_dependentid, $content, $anchor);
        $anchor->parentNode->replaceChild($replacementElement, $anchor);

        $this->migLog->logMessage($pid, 'Link created to perc ID: '
              . $sys_dependentid, E_NOTICE, 'LINK REPLACEMENT');
      }
    }
    // Returned the processed document value.
    $body = $doc->find('body');
    $size = $body->count();
    if ($size > 0) {
      $value = $body->html();
    }

    return $value;
  }

  /**
   * Create a linkit embed to insert into text.
   *
   * @return string
   *   Returns the linkit embed for this node.
   */
  public function createLinkitEmbed($entity_id, $content, $anchor) {
    $entity_type = 'node';
    $entity_storage = \Drupal::entityTypeManager()->getStorage($entity_type);
    $entity = $entity_storage->load($entity_id);

    $element = $this->doc->createElement('a');
    $element->appendChild($this->doc->createTextNode($content));

    $attributes = [];

    if (!empty($entity)) {
      // It's a migrate link. Set the required drupal attributes.
      $attributes = [
        'data-entity-substitution' => 'canonical',
        'data-entity-type' => $entity_type,
        'href' => '/node/' . $entity_id,
        'data-entity-uuid' => $entity->get('uuid')->value,
      ];
    }
    else {
      // The entity doesn't live on this Drupal instance.
      // Check to see if it's a cross site link. If so, create a static link.
      // Parse the json.
      $module_handler = \Drupal::service('module_handler');
      $module_path = $module_handler->getModule('cgov_migration')->getPath();
      $json = file_get_contents($module_path . '/migrations/cross-site-links.json');

      // Format the data to a more manageable array.
      $link_array = json_decode($json, TRUE);
      $cross_links = $this->groupAndFlattenArray($link_array, 'DEPENDENT_ID');

      // Set the static URL associated with this non-instance entity_id.
      $matchFound = FALSE;
      if (array_key_exists($entity_id, $cross_links)) {
        $this->migLog->logMessage('0', 'Replacing non-loaded link with entity ID ' . $entity_id .
              ' and link text: ' . $content, E_NOTICE, $entity_id);
        $matchFound = TRUE;
        $element->setAttribute('href', 'https://' . $cross_links[$entity_id]);
      }
      if (!$matchFound) {
        $this->migLog->logMessage('0', 'WARNING: No matching entity or URL found for this ID: ' .
            $entity_id, E_WARNING, $entity_id);
      }
    }

    // Set the following pre-existing attributes from in the incoming link.
    $attributeType = ['class', 'id', 'style', 'target', 'a', 'onclick', 'rel'];
    foreach ($attributeType as $type) {
      $attrValue = $anchor->getAttribute($type);
      if (!empty($attrValue)) {
        $attributes[$type] = $attrValue;
      }
    }

    foreach ($attributes as $key => $value) {
      $element->setAttribute($key, $value);
    }

    return $element;
  }

  /**
   * Helper function. Group array data, then flatten, by key.
   */
  private function groupAndFlattenArray($arr, $group, $preserveGroupKey = FALSE, $preserveSubArrays = FALSE) {
    $temp = [];
    foreach ($arr as $key => $value) {
      $groupValue = $value[$group];
      if (!$preserveGroupKey) {
        unset($arr[$key][$group]);
      }
      if (!array_key_exists($groupValue, $temp)) {
        $temp[$groupValue] = [];
      }

      if (!$preserveSubArrays) {
        $data = count($arr[$key]) == 1 ? array_pop($arr[$key]) : $arr[$key];
      }
      else {
        $data = $arr[$key];
      }
      $temp[$groupValue][] = $data;
    }

    // Flatten the grouped array.
    foreach ($temp as &$tempKey) {
      $tempKey = $tempKey[0];
    }

    return $temp;
  }

}
