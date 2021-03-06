<?php

/**
 * @file
 * Contains \Drupal\media_entity_instagram\Plugin\MediaEntity\Type\Instagram.
 */

namespace Drupal\media_entity_instagram\Plugin\MediaEntity\Type;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Entity\EntityManager;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\media_entity\MediaBundleInterface;
use Drupal\media_entity\MediaInterface;
use Drupal\media_entity\MediaTypeException;
use Drupal\media_entity\MediaTypeInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;


/**
 * Provides media type plugin for Instagram.
 *
 * @MediaType(
 *   id = "instagram",
 *   label = @Translation("Instagram"),
 *   description = @Translation("Provides business logic and metadata for Instagram.")
 * )
 */
class Instagram extends PluginBase implements MediaTypeInterface, ContainerFactoryPluginInterface {
  use StringTranslationTrait;

  /**
   * List of validation regular expressions.
   *
   * @var array
   */
  protected $validationRegexp = array(
    '@((http|https):){0,1}//(www\.){0,1}instagram\.com/p/(?<shortcode>[a-z0-9_-]+)@i' => 'shortcode',
    '@((http|https):){0,1}//(www\.){0,1}instagr\.am/p/(?<shortcode>[a-z0-9_-]+)@i' => 'shortcode',
  );

  /**
   * Plugin label.
   *
   * @var string
   */
  protected $label;

  /**
   * {@inheritdoc}
   */
  public function label() {
    return $this->label;
  }

  /**
   * The entity manager object.
   *
   * @var \Drupal\Core\Entity\EntityManager;
   */
  protected $entityManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity.manager')
    );
  }

  /**
   * Constructs a new class instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param EntityManager $entity_manager
   *   EntityManager object.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityManager $entity_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityManager = $entity_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function providedFields() {
    $fields = array(
      'shortcode' => $this->t('Instagram shortcode'),
    );

    if ($this->configuration['use_instagram_api']) {
      $fields += array(
        'id' => $this->t('Media ID'),
        'type' => $this->t('Media type: image or video'),
        'thumbnail' => $this->t('Link to the thumbnail'),
        'username' => $this->t('Author of the post'),
        'caption' => $this->t('Caption'),
        'tags' => $this->t('Tags'),
      );
    }

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getField(MediaInterface $media, $name) {
    $matches = $this->matchRegexp($media);

    if (!$matches['shortcode']) {
      return FALSE;
    }

    if ($name == 'shortcode') {
      return $matches['shortcode'];
    }

    // If we have auth settings return the other fields.
    if ($this->configuration['use_instagram_api'] && $instagram = $this->fetchInstagram($matches['shortcode'])) {
      switch ($name) {
        case 'id':
          if (isset($instagram->id)) {
            return $instagram->id;
          }
          return FALSE;

        case 'type':
          if (isset($instagram->type)) {
            return $instagram->type;
          }
          return FALSE;

        case 'thumbnail':
          if (isset($instagram->images->thumbnail->url)) {
            return $instagram->images->thumbnail->url;
          }
          return FALSE;

        case 'username':
          if (isset($instagram->user->username)) {
            return $instagram->user->username;
          }
          return FALSE;

        case 'caption':
          if (isset($instagram->caption->text)) {
            return $instagram->caption->text;
          }
          return FALSE;

        case 'tags':
          if (isset($instagram->tags)) {
            return implode(" " , $instagram->tags);
          }
          return FALSE;
      }
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(MediaBundleInterface $bundle) {
    $form = array();

    $options = array();
    $allowed_field_types = array('string', 'string_long', 'link');
    foreach ($this->entityManager->getFieldDefinitions('media', $bundle->id()) as $field_name => $field) {
      if (in_array($field->getType(), $allowed_field_types) && !$field->getFieldStorageDefinition()->isBaseField()) {
        $options[$field_name] = $field->getLabel();
      }
    }

    $form['source_field'] = array(
      '#type' => 'select',
      '#title' => t('Field with source information'),
      '#description' => t('Field on media entity that stores Instagram embed code or URL.'),
      '#default_value' => empty($this->configuration['source_field']) ? NULL : $this->configuration['source_field'],
      '#options' => $options,
    );

    $form['use_instagram_api'] = array(
      '#type' => 'select',
      '#title' => t('Whether to use Instagram api to fetch instagrams or not.'),
      '#description' => t("In order to use Instagram's api you have to create a developer account and an application. For more information consult the readme file."),
      '#default_value' => empty($this->configuration['use_instagram_api']) ? 0 : $this->configuration['use_instagram_api'],
      '#options' => array(
        0 => t('No'),
        1 => t('Yes'),
      ),
    );

    // @todo Evaluate if this should be a site-wide configuration.
    $form['client_id'] = array(
      '#type' => 'textfield',
      '#title' => t('Client ID'),
      '#default_value' => empty($this->configuration['client_id']) ? NULL : $this->configuration['client_id'],
      '#states' => array(
        'visible' => array(
          ':input[name="type_configuration[instagram][use_instagram_api]"]' => array('value' => '1'),
        ),
      ),
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validate(MediaInterface $media) {
    $matches = $this->matchRegexp($media);

    // Validate regex.
    if (!$matches) {
      throw new MediaTypeException($this->configuration['source_field'], 'Not valid URL/embed code.');
    }
  }

  /**
   * Runs preg_match on embed code/URL.
   *
   * @param MediaInterface $media
   *   Media object.
   *
   * @return array|bool
   *   Array of preg matches or FALSE if no match.
   *
   * @see preg_match()
   */
  protected function matchRegexp(MediaInterface $media) {
    $matches = array();
    $source_field = $this->configuration['source_field'];

    $property_name = $media->{$source_field}->first()->mainPropertyName();
    foreach ($this->validationRegexp as $pattern => $key) {
      if (preg_match($pattern, $media->{$source_field}->{$property_name}, $matches)) {
        return $matches;
      }
    }

    return FALSE;
  }

  /**
   * Get a single instagram.
   *
   * @param string $shortcode
   *   The instagram shortcode.
   */
  protected function fetchInstagram($shortcode) {
    $instagram = &drupal_static(__FUNCTION__);

    if (!isset($instagram)) {
      // Check for dependencies.
      // @todo There is perhaps a better way to do that.
      if (!class_exists('\Instagram\Instagram')) {
        drupal_set_message(t('Instagram library is not available. Consult the README.md for installation instructions.'), 'error');
        return;
      }

      if (!isset($this->configuration['client_id'])) {
        drupal_set_message(t('The client ID is not available. Consult the README.md for installation instructions.'), 'error');
        return;
      }
      if (empty($this->configuration['client_id'])) {
        drupal_set_message(t('The client ID is missing. Please add it in your Instagram settings.'), 'error');
        return;
      }
      $instagram_object = new \Instagram\Instagram;
      $instagram_object->setClientID($this->configuration['client_id']);
      $result = $instagram_object->getMediaByShortcode($shortcode)->getData();

      if ($result) {
        return $result;
      }
      else {
        throw new MediaTypeException(NULL, 'The media could not be retrieved.');
      }
    }
  }

}
