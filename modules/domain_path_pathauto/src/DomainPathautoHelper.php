<?php

namespace Drupal\domain_path_pathauto;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * DomainPathauto helper service.
 */
class DomainPathautoHelper {

  use StringTranslationTrait;

  /**
   * The EntityTypeManager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * DomainPathautoGenerator service.
   *
   * @var \Drupal\domain_path_pathauto\DomainPathautoGenerator
   */
  protected DomainPathautoGenerator $domainPathautoGenerator;

  /**
   * DomainPathautoHelper constructor.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, DomainPathautoGenerator $domain_pathauto_generator) {
    $this->entityTypeManager = $entity_type_manager;
    $this->domainPathautoGenerator = $domain_pathauto_generator;
  }

  /**
   * The domain path_auto form element for the entity form.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Related entity.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function alterEntityForm(array &$form, FormStateInterface $form_state, ContentEntityInterface $entity) {
    $domains = $this->entityTypeManager->getStorage('domain')
      ->loadMultipleSorted();
    foreach ($domains as $domain_id => $domain) {
      // See https://git.drupalcode.org/project/pathauto/-/blob/8.x-1.x/src/PathautoWidget.php#L42
      // Generate checkboxes per each domain.
      if (isset($form['path']['widget'][0]['pathauto']) && $form['path']['widget'][0]['pathauto']['#type'] === 'checkbox') {
        $form['path']['widget'][0]['domain_path'][$domain_id]['pathauto'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Generate automatic URL alias for @domain', ['@domain' => Html::escape(rtrim($domain->getPath(), '/'))]),
          '#default_value' => $this->domainPathautoGenerator->domainPathPathautoGenerationIsEnabled($entity, $domain->id()),
          '#weight' => -1,
        ];
      }
      // Disable form element if the "delete-checkbox" is active or automatic
      // creation of alias is checked.
      $form['path']['widget'][0]['domain_path'][$domain_id]['path']['#states'] = [
        'disabled' => [
          ['input[name="path[0][domain_path][domain_path_delete]"]' => ['checked' => TRUE]],
          'OR',
          ['input[name="path[0][domain_path][' . $domain_id . '][pathauto]"]' => ['checked' => TRUE]],
        ],
      ];
    }
    $form['#validate'][] = [$this, 'validateAlteredForm'];
    if (!empty($form['actions'])) {
      if (array_key_exists('submit', $form['actions'])) {
        $form['actions']['submit']['#submit'][] = [
          $this,
          'submitAlteredEntityForm',
        ];
      }
    }
    else {
      // If no actions we just tack it on to the form submit handlers.
      $form['#submit'][] = [$this, 'submitAlteredEntityForm'];
    }
  }

  /**
   * Validation handler.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state object.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function validateAlteredForm(array &$form, FormStateInterface $form_state) {
    // Set up variables.
    $entity = $form_state->getFormObject()->getEntity();
    $path_values = $form_state->getValue('path');
    $domain_path_values = $path_values[0]['domain_path'];
    $alias = $path_values[0]['alias'] ?? NULL;
    // Check domain access settings if they are on the form.
    $domain_access = [];
    if (!empty($form['field_domain_access']) && !empty($form_state->getValue('field_domain_access'))) {
      foreach ($form_state->getValue('field_domain_access') as $item) {
        $domain_access[$item['target_id']] = $item['target_id'];
      }
    }
    $domain_access_all = empty($form['field_domain_all_affiliates']) || $form_state->getValue('field_domain_all_affiliates')['value'];
    // Validate each path value.
    foreach ($domain_path_values as $domain_id => $domain_path_data) {

      // Don't validate if the domain doesn't have access (we remove aliases
      // for domains that don't have access to this entity).
      $domain_has_access = $domain_access_all || ($domain_access && !empty($domain_access[$domain_id]));
      if (!$domain_has_access) {
        continue;
      }
      // If domain pathauto is not enabled, validate user entered path.
      if ($domain_path_data['pathauto']) {
        $path = $domain_path_data['path'];
        if (!empty($path) && $path === $alias) {
          $form_state->setError($form['path']['widget'][0]['domain_path'][$domain_id], $this->t('Domain path "%path" matches the default path alias. You may leave the element blank.', ['%path' => $path]));
        }
        elseif (!empty($path)) {
          // Trim slashes and whitespace from end of path value.
          $path_value = rtrim(trim($path), " \\/");

          // Check that the paths start with a slash.
          if ($path_value && $path_value[0] !== '/') {
            $form_state->setError($form['path']['widget'][0]['domain_path'][$domain_id]['path'], $this->t('Domain path "%path" needs to start with a slash.', ['%path' => $path]));
          }

          // Check for duplicates.
          $entity_query = $this->entityTypeManager->getStorage('domain_path')
            ->getQuery();
          $entity_query->condition('domain_id', $domain_id)
            ->condition('alias', $path_value);
          if (!$entity->isNew()) {
            $entity_query->condition('source', '/' . $entity->toUrl()->getInternalPath(), '<>');
          }
          $result = $entity_query->execute();
          if ($result) {
            $form_state->setError($form['path']['widget'][0]['domain_path'][$domain_id]['path'], $this->t('Domain path %path matches an existing domain path alias', ['%path' => $path_value]));
          }
        }
        if (isset($path_value)) {
          $domain_path_values[$domain_id] = $path_value;
        }
      }
      $form_state->setValue('domain_path', $domain_path_values);
    }
  }

  /**
   * Submit handler.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state object.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException|\Drupal\Core\Entity\EntityStorageException
   */
  public function submitAlteredEntityForm(array $form, FormStateInterface $form_state) {
    $path_values = $form_state->getValue('path');
    $domain_path_values = $path_values[0]['domain_path'];
    $entity = $form_state->getFormObject()->getEntity();
    $entity_system_path = '/' . $entity->toUrl()->getInternalPath();
    $properties = [
      'source' => $entity_system_path,
      'language' => $entity->language()->getId(),
    ];
    $domain_access_all = empty($form['field_domain_all_affiliates']) || $form_state->getValue('field_domain_all_affiliates')['value'];
    // Check domain access settings if they are on the form.
    $domain_access = [];
    if (!empty($form['field_domain_access']) && !empty($form_state->getValue('field_domain_access'))) {
      foreach ($form_state->getValue('field_domain_access') as $item) {
        $domain_access[$item['target_id']] = $item['target_id'];
      }
    }
    // If not set to delete, then save changes.
    if (empty($domain_path_values['domain_path_delete'])) {
      unset($domain_path_values['domain_path_delete']);
      foreach ($domain_path_values as $domain_id => $domain_path_data) {

        $alias = trim($domain_path_data['path']);
        if ($domain_path_data['pathauto']) {
          // Generate alias using pathauto.
          $alias = $this->domainPathautoGenerator->createEntityAlias($entity, 'return', $domain_id);
          // Remember pathauto default enabled setting.
          $this->domainPathautoGenerator->setDomainPathPathautoState($entity, $domain_id, TRUE);
        }
        else {
          // Delete pathauto default enabled setting.
          $this->domainPathautoGenerator->deleteDomainPathPathautoState($entity, $domain_id);
        }
        // Get the existing domain path for this domain if it exists.
        $properties['domain_id'] = $domain_id;
        $domain_paths = $this->entityTypeManager->getStorage('domain_path')
          ->loadByProperties($properties);
        $domain_has_access = $domain_access_all || ($domain_access && !empty($domain_access[$domain_id]));
        $domain_path = $domain_paths ? reset($domain_paths) : NULL;
        // We don't want to save the alias if the domain path field is empty,
        // or if the domain doesn't have
        // access to this entity.
        if (!$alias || !$domain_has_access) {
          // Delete the existing domain path.
          if ($domain_path) {
            $domain_path->delete();
          }
          continue;
        }

        // Create or update the domain path.
        $properties_map = [
          'alias' => $alias,
          'domain_id' => $domain_id,
        ] + $properties;
        if (!$domain_path) {
          $domain_path = $this->entityTypeManager->getStorage('domain_path')
            ->create(['type' => 'domain_path']);
          foreach ($properties_map as $field => $value) {
            $domain_path->set($field, $value);
          }
          $domain_path->save();
        }
        elseif ($domain_path->get('alias')->value !== $alias) {
          $domain_path->set('alias', $alias);
          $domain_path->save();
        }
      }
    }
  }

}
