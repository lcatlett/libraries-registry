<?php

namespace Drupal\libraries_registry\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Defines a form that configures global Juicebox settings.
 */
class ProcessingForm extends FormBase {

  /**
   * A Drupal module manager service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleManager;

  /**
   * A Drupal serializer service.
   *
   * @var \Symfony\Component\Serializer\SerializerInterface
   */
  protected $serializer;

  /**
   * Class constructor.
   */
  public function __construct(ModuleHandlerInterface $module_manager, SerializerInterface $serializer) {
    $this->moduleManager = $module_manager;
    $this->serializer = $serializer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    // Instantiates this form class.
    return new static($container->get('module_handler'), $container->get('serializer'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormID() {
    return 'libraries_registry_process_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = array(
      '#type' => 'submit',
      '#value' => $this->t('Process'),
      '#button_type' => 'primary',
    );
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Loop through libraries_info hooks, serialize, and save to
    // library-registry stream wrapper.
    $libraries_info = $this->moduleManager->invokeAll('libraries_info');
    $saved_count = 0;
    foreach ($libraries_info as $library_name => $data) {
      $this->preSerializeAlter($library_name, $data);
      // @todo: Switch to YAML
      // @see https://www.drupal.org/node/1897612
      $serialized = $this->serializer->serialize($data, 'json', ['json_encode_options' => JSON_PRETTY_PRINT]);
      if ($serialized) {
        $this->postSerializeAlter($library_name, $serialized);
        $filename = $library_name . '.json';
        $saved_path = file_unmanaged_save_data($serialized, 'library-registry://' . $filename, FILE_EXISTS_REPLACE);
        $saved_count += !empty($saved_path);
      }
    }
    drupal_set_message(t('@count registry files were updated.', ['@count' => $saved_count]));
  }

  /**
   * Alter library array date before serialize.
   *
   * @param string $library_name
   *   The machine name of the library.
   * @param array $library_data
   *   An array of library data as fetced from hook_libraries_info to be
   *   altered.
   */
  protected function preSerializeAlter(&$library_name, array &$library_data) {
    // Get an id based on library name. We'll also use this as the raw filename.
    $id = strtolower(preg_replace('/[^0-9a-zA-Z]/', '_', $library_name));
    $library_name = $id;
    // Add a type to the data (assume an asset library for now).
    $library_data = array('type' => 'asset') + $library_data;
    // Callbacks are likely no longer relevant.
    unset($library_data['callbacks']);
    // Process files including variant support.
    if (!empty($library_data['files'])) {
      $this->convertFiles($library_name, $library_data);
    }
    if (!empty($library_data['variants'])) {
      foreach ($library_data['variants'] as $variant_name => &$variant_data) {
        $this->convertFiles($library_name, $variant_data);
      }
    }
  }

  /**
   * Convert D7 files array to D8 structures.
   *
   * @param string $library_name
   *   The machine name of the library.
   * @param array $element
   *   A library definition array element to test for files data and convert if
   *   needed.
   */
  protected function convertFiles(&$library_name, array &$element) {
    if (!empty($element['files'])) {
      // Process CSS definitions.
      if (!empty($element['files']['css'])) {
        $element['css']['base'] = array(); // Assume base for modules
        foreach ($element['files']['css'] as $file => $data) {
          $element['css']['base'][$file] = $data;
        }
      }
      // Process JS
      if (!empty($element['files']['js'])) {
        $element['js'] = $element['files']['js'];
      }
      unset($element['files']);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function postSerializeAlter(&$library_name, &$library_data_serialized) {

  }

}
