<?php
/**
 * @file
 * Contains \Drupal\geolocation\Plugin\Field\FieldFormatter\GeolocationGoogleMapFormatter.
 */

namespace Drupal\geolocation\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'geolocation_latlng' formatter.
 *
 * @FieldFormatter(
 *   id = "geolocation_map",
 *   module = "geolocation",
 *   label = @Translation("Geolocation Google Map"),
 *   field_types = {
 *     "geolocation"
 *   }
 * )
 */
class GeolocationGoogleMapFormatter extends FormatterBase {

  const ROADMAP = 'ROADMAP';
  const SATELLITE = 'SATELLITE';
  const HYBRID = 'HYBRID';
  const TERRAIN = 'TERRAIN';

  static $mapTypes = [
    self::ROADMAP => 'Road map view',
    self::SATELLITE => 'Google Earth satellite images',
    self::HYBRID => 'A mixture of normal and satellite views',
    self::TERRAIN => 'A physical map based on terrain information',
  ];

  /**
   * {@inheritdoc}
   */
  public function __construct($plugin_id, $plugin_definition, \Drupal\Core\Field\FieldDefinitionInterface $field_definition, array $settings, $label, $view_mode, array $third_party_settings) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return array(
      'type' => static::ROADMAP,
      'zoom' => 10,
      'height' => '400px',
      'width' => '100%',
      'title' => '',
      'info_text' => '',
    ) + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $settings = $this->getSettings();

    $elements['type'] = array(
      '#type' => 'select',
      '#title' => $this->t('Default map type'),
      '#options' => $this->getMapTypes(),
      '#default_value' =>  $settings['type'],
    );
    $elements['zoom'] = array(
      '#type' => 'select',
      '#title' => $this->t('Zoom level'),
      '#options' => range(0, 18),
      '#description' => $this->t('The initial resolution at which to display the map, where zoom 0 corresponds to a map of the Earth fully zoomed out, and higher zoom levels zoom in at a higher resolution.'),
      '#default_value' => $settings['zoom'],
    );
    $elements['height'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Height'),
      '#description' => $this->t('Enter the dimensions and the measurement units. E.g. 200px or 100%.'),
      '#size' => 4,
      '#default_value' => $settings['height'],
    );
    $elements['width'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Width'),
      '#description' => $this->t('Enter the dimensions and the measurement units. E.g. 200px or 100%.'),
      '#size' => 4,
      '#default_value' => $settings['width'],
    );
    $elements['info_text'] = array(
      '#type' => 'textarea',
      '#title' => $this->t('Info Text'),
      '#description' => $this->t('The "Info Text" is displayed when a user clicks on a map marker..'),
      '#default_value' => $settings['info_text'],
    );
    $elements['title'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Hover Title'),
      '#description' => $this->t('The hover title is a tool tip that will be displayed when the mouse is paused over the map marker.'),
      '#default_value' => $settings['title'],
      '#suffix' => $this->t('<h4>The following tokens are available:</h4><p>%lat: Latitude, %lng: Longitude</p>'),
    );

    // Add the token UI from the token module if present.
    $elements['token_help'] = [
      '#theme' => 'token_tree',
      '#token_types' => [$this->fieldDefinition->getTargetEntityTypeId()],
    ];

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $settings = $this->getSettings();
    $types = $this->getMapTypes();
    $summary = [];
    $summary[] = $this->t('Type: @type', ['@type' => $types[$settings['type']]]);
    $summary[] = $this->t('Zoom level: @zoom', ['@zoom' => $settings['zoom']]);
    $summary[] = $this->t('Height: @height', ['@height' => $settings['height']]);
    $summary[] = $this->t('Width: @width', ['@width' => $settings['width']]);
    $summary[] = $this->t('Info Text: @type', ['@type' => current(explode(chr(10), wordwrap($settings['info_text'], 30)))]);
    $summary[] = $this->t('Hover Title: @type', ['@type' => $settings['title']]);
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    // Add formatter settings to the drupalSettings array.
    $field_settings = $this->getSettings();
    $elements =  [];
    // This is a list of tokenized settings that should have placeholders
    // replaced with contextual values.
    $tokenized_settings = [
      'info_text',
      'title',
    ];

    foreach ($items as $delta => $item) {
      // @todo: Add token support to the geolocaiton field exposing sub-fields.
      // Get token context.
      $token_context = [
        'field' => $items,
        $this->fieldDefinition->getTargetEntityTypeId() => $items->getEntity(),
      ];

      $uniqueue_id = uniqid("map-canvas-");

      $elements[$delta] = [
        '#type' => 'markup',
        '#markup' => '<div id="' . $uniqueue_id . '" class="geolocation-google-map"></div>',
        '#attached' => [
          'library' => ['geolocation/geolocation.maps'],
          'drupalSettings' => [
            'geolocation' => [
              'maps' => [
                $uniqueue_id => [
                  'id' => "{$uniqueue_id}",
                  'lat' => (float)$item->lat,
                  'lng' => (float)$item->lng,
                  'settings' => $field_settings,
                ],
              ],
            ],
          ],
        ],
      ];

      // Replace placeholders with token values.
      $item_settings = &$elements[$delta]['#attached']['drupalSettings']['geolocation']['maps'][$uniqueue_id]['settings'];
      array_walk($tokenized_settings, function ($v) use (&$item_settings, $token_context) {
        $item_settings[$v] = \Drupal::token()->replace($item_settings[$v], $token_context);
      });

    }
    return $elements;
  }

  /**
   * An array of all available map types.
   *
   * @return array
   */
  private function getMapTypes() {
    // Translate values.
    return array_map([$this, 't'], static::$mapTypes);
  }
}
