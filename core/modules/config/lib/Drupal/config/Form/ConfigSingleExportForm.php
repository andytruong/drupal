<?php

/**
 * @file
 * Contains \Drupal\config\Form\ConfigSingleExportForm.
 */

namespace Drupal\config\Form;

use Drupal\Component\Utility\MapArray;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Form\FormBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for exporting a single configuration file.
 */
class ConfigSingleExportForm extends FormBase {

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityManagerInterface
   */
  protected $entityManager;

  /**
   * The config storage.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $configStorage;

  /**
   * Tracks the valid config entity type definitions.
   *
   * @var \Drupal\Core\Entity\EntityTypeInterface[]
   */
  protected $definitions = array();

  /**
   * Constructs a new ConfigSingleImportForm.
   *
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   *   The entity manager.
   * @param \Drupal\Core\Config\StorageInterface $config_storage
   *   The config storage.
   */
  public function __construct(EntityManagerInterface $entity_manager, StorageInterface $config_storage) {
    $this->entityManager = $entity_manager;
    $this->configStorage = $config_storage;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.manager'),
      $container->get('config.storage')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormID() {
    return 'config_single_export_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, array &$form_state, $config_type = NULL, $config_name = NULL) {
    foreach ($this->entityManager->getDefinitions() as $entity_type => $definition) {
      if ($definition->getConfigPrefix() && $definition->hasKey('uuid')) {
        $this->definitions[$entity_type] = $definition;
      }
    }
    $entity_types = array_map(function (EntityTypeInterface $definition) {
      return $definition->getLabel();
    }, $this->definitions);
    // Sort the entity types by label, then add the simple config to the top.
    uasort($entity_types, 'strnatcasecmp');
    $config_types = array(
      'system.simple' => $this->t('Simple configuration'),
    ) + $entity_types;
    $form['config_type'] = array(
      '#title' => $this->t('Configuration type'),
      '#type' => 'select',
      '#options' => $config_types,
      '#default_value' => $config_type,
      '#ajax' => array(
        'callback' => array($this, 'updateConfigurationType'),
        'wrapper' => 'edit-config-type-wrapper',
      ),
    );
    $default_type = isset($form_state['values']['config_type']) ? $form_state['values']['config_type'] : $config_type;
    $form['config_name'] = array(
      '#title' => $this->t('Configuration name'),
      '#type' => 'select',
      '#options' => $this->findConfiguration($default_type),
      '#default_value' => $config_name,
      '#required' => TRUE,
      '#prefix' => '<div id="edit-config-type-wrapper">',
      '#suffix' => '</div>',
      '#ajax' => array(
        'callback' => array($this, 'updateExport'),
        'wrapper' => 'edit-export-wrapper',
      ),
    );

    $form['export'] = array(
      '#title' => $this->t('Here is your configuration:'),
      '#type' => 'textarea',
      '#rows' => 24,
      '#required' => TRUE,
      '#prefix' => '<div id="edit-export-wrapper">',
      '#suffix' => '</div>',
    );
    if ($config_type && $config_name) {
      $fake_form_state = array('values' => array(
        'config_type' => $config_type,
        'config_name' => $config_name,
      ));
      $form['export'] = $this->updateExport($form, $fake_form_state);
    }
    return $form;
  }

  /**
   * Handles switching the configuration type selector.
   */
  public function updateConfigurationType($form, &$form_state) {
    $form['config_name']['#options'] = $this->findConfiguration($form_state['values']['config_type']);
    return $form['config_name'];
  }

  /**
   * Handles switching the export textarea.
   */
  public function updateExport($form, &$form_state) {
    // Determine the full config name for the selected config entity.
    if ($form_state['values']['config_type'] !== 'system.simple') {
      $definition = $this->entityManager->getDefinition($form_state['values']['config_type']);
      $name = $definition->getConfigPrefix() . '.' . $form_state['values']['config_name'];
    }
    // The config name is used directly for simple configuration.
    else {
      $name = $form_state['values']['config_name'];
    }
    // Read the raw data for this config name, encode it, and display it.
    $data = $this->configStorage->read($name);
    $form['export']['#value'] = $this->configStorage->encode($data);
    $form['export']['#description'] = $this->t('The filename is %name.', array('%name' => $name . '.yml'));
    return $form['export'];
  }

  /**
   * Handles switching the configuration type selector.
   */
  protected function findConfiguration($config_type) {
    $names = array(
      '' => $this->t('- Select -'),
    );
    // For a given entity type, load all entities.
    if ($config_type && $config_type !== 'system.simple') {
      $entity_storage = $this->entityManager->getStorageController($config_type);
      foreach ($entity_storage->loadMultiple() as $entity) {
        $entity_id = $entity->id();
        $label = $entity->label() ?: $entity_id;
        $names[$entity_id] = $label;
      }
    }
    // Handle simple configuration.
    else {
      // Gather the config entity prefixes.
      $config_prefixes = array_map(function (EntityTypeInterface $definition) {
        return $definition->getConfigPrefix() . '.';
      }, $this->definitions);

      // Find all config, and then filter our anything matching a config prefix.
      $names = MapArray::copyValuesToKeys($this->configStorage->listAll());
      foreach ($names as $config_name) {
        foreach ($config_prefixes as $config_prefix) {
          if (strpos($config_name, $config_prefix) === 0) {
            unset($names[$config_name]);
          }
        }
      }
    }
    return $names;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, array &$form_state) {
    // Nothing to submit.
  }

}
