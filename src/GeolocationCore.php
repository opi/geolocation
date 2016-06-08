<?php

namespace Drupal\geolocation;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\field\FieldStorageConfigInterface;

/**
 * Class GeolocationCore.
 *
 * @package Drupal\geolocation
 */
class GeolocationCore {
  use StringTranslationTrait;

  const EARTH_RADIUS_KM = 6371;
  const EARTH_RADIUS_MILE = 3959;
  /**
   * Drupal\Core\Extension\ModuleHandler definition.
   *
   * @var \Drupal\Core\Extension\ModuleHandler
   */
  protected $moduleHandler;

  /**
   * Drupal\Core\Entity\EntityManager definition.
   *
   * @var \Drupal\Core\Entity\EntityManager
   */
  protected $entityManager;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   A module handler.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_manager
   *   An EntityTypeManager instance.
   */
  public function __construct(ModuleHandlerInterface $module_handler, EntityTypeManagerInterface $entity_manager) {
    $this->moduleHandler = $module_handler;
    $this->entityManager = $entity_manager;
  }

  /**
   * Get views data for fields and filters.
   *
   * @param \Drupal\field\FieldStorageConfigInterface $field_storage
   *   Storage of the current field.
   *
   * @return array
   *   The data to return to Views.
   */
  public function getViewsFieldData(FieldStorageConfigInterface $field_storage) {

    // Make sure views.views.inc is loaded.
    module_load_include('inc', 'views', 'views.views');

    // Get the default data from the views module.
    $data = views_field_default_views_data($field_storage);

    // Loop through all of the results and set our overrides.
    foreach ($data as $table_name => $table_data) {
      foreach ($table_data as $field_name => $field_data) {
        // Only modify fields.
        if ($field_name != 'delta') {
          if (isset($field_data['field'])) {
            // Use our own field handler.
            $data[$table_name][$field_name]['field']['id'] = 'geolocation_field';
            $data[$table_name][$field_name]['field']['click sortable'] = FALSE;
          }
          if (isset($field_data['filter'])) {
            // The default filters aren't useful at all so remove them.
            unset($data[$table_name][$field_name]['filter']);
          }
          if (isset($field_data['argument'])) {
            // The default arguments aren't useful at all so remove them.
            unset($data[$table_name][$field_name]['argument']);
          }
          if (isset($field_data['sort'])) {
            // The default arguments aren't useful at all so remove them.
            unset($data[$table_name][$field_name]['sort']);
          }
        }
      }

      $args = ['@field_name' => $field_storage->getName()];

      $field_coordinates_table_data = [];
      $entity_type_id = $field_storage->getTargetEntityTypeId();
      $target_entity_type = \Drupal::entityTypeManager()->getDefinition($field_storage->getTargetEntityTypeId());

      if (array_key_exists($target_entity_type->getBaseTable() . '__' . $field_storage->getName(), $data)) {
        $field_coordinates_table_data = $data[$target_entity_type->getBaseTable() . '__' . $field_storage->getName()][$field_storage->getName()];
      }
      elseif (array_key_exists($entity_type_id . '__' . $field_storage->getName(), $data)) {
        // Fall back to using the key format as defined in,
        // views_field_default_views_data().
        $field_coordinates_table_data = $data[$entity_type_id . '__' . $field_storage->getName()][$field_storage->getName()];
      }

      // Add proximity handlers.
      $data[$table_name][$args['@field_name'] . '_proximity'] = [
        'group' => $target_entity_type->getLabel(),
        'title' => $this->t('Proximity (@field_name)', $args),
        'title short' => isset($field_coordinates_table_data['title short']) ? $field_coordinates_table_data['title short'] . t(":proximity") : '',
        'help' => isset($field_coordinates_table_data['help']) ? $field_coordinates_table_data['help'] : '',
        'argument' => [
          'id' => 'geolocation_argument_proximity',
          'table' => $table_name,
          'entity_type' => $entity_type_id,
          'field_name' => $args['@field_name'] . '_proximity',
          'real field' => $args['@field_name'],
          'label' => $this->t('Distance to !field_name', $args),
          'empty field name' => '- No value -',
          'additional fields' => [
            $args['@field_name'] . '_lat',
            $args['@field_name'] . '_lng',
            $args['@field_name'] . '_lat_sin',
            $args['@field_name'] . '_lat_cos',
            $args['@field_name'] . '_lng_rad',
          ],
        ],
        'filter' => [
          'id' => 'geolocation_filter_proximity',
          'table' => $table_name,
          'entity_type' => $entity_type_id,
          'field_name' => $args['@field_name'] . '_proximity',
          'real field' => $args['@field_name'],
          'label' => $this->t('Distance to !field_name', $args),
          'allow empty' => TRUE,
          'additional fields' => [
            $args['@field_name'] . '_lat',
            $args['@field_name'] . '_lng',
            $args['@field_name'] . '_lat_sin',
            $args['@field_name'] . '_lat_cos',
            $args['@field_name'] . '_lng_rad',
          ],
        ],
        'field' => [
          'table' => $table_name,
          'id' => 'geolocation_field_proximity',
          'field_name' => $args['@field_name'] . '_proximity',
          'entity_type' => $entity_type_id,
          'real field' => $args['@field_name'],
          'additional fields' => [
            $args['@field_name'] . '_lat',
            $args['@field_name'] . '_lng',
            $args['@field_name'] . '_lat_sin',
            $args['@field_name'] . '_lat_cos',
            $args['@field_name'] . '_lng_rad',
          ],
          'element type' => 'div',
          'is revision' => (isset($table_data[$args['@field_name']]['field']['is revision']) && $table_data[$args['@field_name']]['field']['is revision']),
          'click sortable' => TRUE,
        ],
        'sort' => [
          'table' => $table_name,
          'id' => 'geolocation_sort_proximity',
          'field_name' => $args['@field_name'] . '_proximity',
          'entity_type' => $entity_type_id,
          'real field' => $args['@field_name'],
        ],
      ];
    }

    return $data;
  }

  /**
   * Gets the query fragment for adding a proximity field to a query.
   *
   * @param string $table_name
   *   The proximity table name.
   * @param string $field_id
   *   The proximity field ID.
   * @param string $filter_lat
   *   The latitude to filter for.
   * @param string $filter_lng
   *   The longitude to filter for.
   * @param int $earth_radius
   *   Filter radius.
   *
   * @return string
   *   The fragment to enter to actual query.
   */
  public function getQueryFragment($table_name, $field_id, $filter_lat, $filter_lng, $earth_radius = self::EARTH_RADIUS_KM) {

    // Define the field names.
    $field_latsin = "{$table_name}.{$field_id}_lat_sin";
    $field_latcos = "{$table_name}.{$field_id}_lat_cos";
    $field_lng    = "{$table_name}.{$field_id}_lng_rad";

    // deg2rad() is sensitive to empty strings. Replace with integer zero.
    $filter_lat = empty($filter_lat) ? 0 : $filter_lat;
    $filter_lng = empty($filter_lng) ? 0 : $filter_lng;

    // Pre-calculate filter values.
    $filter_latcos = cos(deg2rad($filter_lat));
    $filter_latsin = sin(deg2rad($filter_lat));
    $filter_lng    = deg2rad($filter_lng);

    return "(
      ACOS(
        $filter_latcos
        * $field_latcos
        * COS( $filter_lng - $field_lng  )
        +
        $filter_latsin
        * $field_latsin
      ) * $earth_radius
    )";

  }

}
