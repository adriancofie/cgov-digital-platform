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

  private $attributeType = [
    'class',
    'id',
    'style',
    'target',
    'a',
    'onclick',
    'rel',
  ];

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
    // NOTE / TODO: Performance-wise would love to initialize this only once,
    // or have it in the database.
    // Maybe on module install populate a DB table with the json values.
    // Setup the Content ID array.
    $translationList = $this->getContentIdArray('translationid.json', 'ID');
    $dcegList = $this->getContentIdArray('cross-site-links-dceg.json', 'CONTENTID');

    // Grab the Entity, either a file or node.
    $entity = $this->getEntityOfUnknownType($entity_id);
    $entity_type = $entity->getEntityType();

    echo('Entity Type:' . $entity_type);

    $attributes = [];

    if (!empty($entity)) {
      // The linked to item has been loaded into this instance as a node or
      // media item.
      // Set the required drupal attributes.
      $attributes = $this->generateAttributes($entity);
    }
    elseif (array_key_exists($entity_id, $translationList)) {
      // The entity id doesn't live on this Drupal instance but is in the
      // reference list of items.
      // Check to see if it's been imported as a translation.
      $url = $translationList[$entity_id]['url'];
      // See if it has an English translation.
      $translationid = $translationList[$entity_id]['translationid'];
      if (!empty($translationid)) {
        // It has an english translation; verify it was loaded into the system.
        $entity_storage = \Drupal::entityTypeManager()->getStorage($entity_type);
        $englishEntity = $entity_storage->load($entity_id);
        if (!empty($englishEntity)) {
          // The item was loaded into the system, generate the spanish url
          // TODO?  Confirm / get the translation programatically.
          $attributes = $this->generateAttributes($entity, 'espanol');

          $this->migLog->logMessage('0', 'Replacing  spanish link which has entity ID ' . $entity_id .
            ' and link text: ' . $content . 'to english id' . $englishEntity->id(), E_NOTICE, $entity_id);
        }
      }
      else {
        // The item was in the list but is not spanish.
        // MEANING: The item has not been loaded into drupal,
        // and it is not a translation.
        // It is either a failed Drupal item, or a cross-site link.
        $this->migLog->logMessage('0', 'WARNING: ' . $entity_id .
           ' is either non-loaded or missing', E_WARNING, 'POSSIBLE FAILURE');
        echo($entity_id . ' was not loaded into the system but found in the reference list.' . PHP_EOL);

        echo('THe URL is: ' . $url);
        // TODO: Create a static link to the content.
      }
    }
    elseif (array_key_exists($entity_id, $dcegList)) {
      // THe item is not in the CGOV/microsite list and
      // has has not been loaded into drupal,
      // Check to see if its unloaded content in the DCEG content list.
    }
    else {
      // The item was:
      // Not loaded into Drupal.
      // Not translated to a new ID in Drupal.
      // Not in the Cgov/microsite list.
      // Not in the CEG list.
      echo($entity_id . ' was not loaded into the system ' . PHP_EOL);
      $this->migLog->logMessage('0', 'WARNING: ' . $entity_id .
        ' is missing from the system and reference list', E_WARNING, 'FAILURE');
    }

    // Create the new element.
    $element = $this->doc->createElement('a');
    $element->appendChild($this->doc->createTextNode($content));

    // Set the following pre-existing attributes from the incoming link.
    foreach ($this->attributeType as $type) {
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
   * Generate link attributes given an entity on indiscriminate type.
   */
  private function generateAttributes($entity, $language = NULL) {

    if ($language) {
      $href = '/' . $language . '/' . $entity->getEntityType() . '/' . $entity->id();
    }
    else {
      $href = '/' . $entity->getEntityType() . '/' . $entity->getID();
    }

    $attributes = [
      'data-entity-substitution' => 'canonical',
      'data-entity-type' => $entity->getEntityType(),
      'href' => $href,
      'data-entity-uuid' => $entity->get('uuid')->value,
    ];

    return $attributes;
  }

  /**
   * Helper function. Group array data, then flatten, by key.
   */
  private function getContentIdArray($filename, $key) {
    // The entity doesn't live on this Drupal instance.
    // Check to see if it's a cross site link. If so, create a static link.
    // Parse the json.
    $module_handler = \Drupal::service('module_handler');
    $module_path = $module_handler->getModule('cgov_migration')->getPath();

    $json = file_get_contents($module_path . '/migrations/' . $filename);

    // Format the data to a more manageable array.
    $link_array = json_decode($json, TRUE);
    $id_array = $this->groupAndFlattenArray($link_array, $key);

    return $id_array;

  }

  /**
   * Returns and entity of an unknown type.
   *
   * Percussion items have a one to one mapping to Drupal so there should be no
   * overlap in terms of node and media entity_ids. We are assuming
   * the incoming ID belongs to one entity type and not both.
   */
  private function getEntityOfUnknownType($entity_id) {

    $entity_storage = \Drupal::entityTypeManager()->getStorage('node');
    $entity = $entity_storage->load($entity_id);

    // Try to load the id as a media item otherwise.
    if (empty($entity)) {
      $entity_storage = \Drupal::entityTypeManager()->getStorage('media');
      $entity = $entity_storage->load($entity_id);
    }

    return $entity;
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
