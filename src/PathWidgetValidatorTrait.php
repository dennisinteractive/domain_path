<?php

namespace Drupal\domain_path;

use Drupal\Core\Form\FormStateInterface;

/**
 * Supplies validator methods for path widgets.
 */
trait PathWidgetValidatorTrait {
  /**
   * @inheritdoc
   */
  public static function validateFormElement(array &$element, FormStateInterface $form_state) {
    // Trim the submitted value of whitespace and slashes.
    $alias = rtrim(trim($element['alias']['#value']), " \\/");
    if (!empty($alias)) {
      $form_state->setValueForElement($element['alias'], $alias);

      // If 'all affiliates' is checked, whether this alias is already in use.
      $allAffiliates = $form_state->getValue('field_domain_all_affiliates');
      if (!empty($allAffiliates['value'])) {
        // Validate that the submitted alias does not exist on any domains.
        $domains = \Drupal::service('domain.loader')->loadMultiple();
        foreach ($domains as $domain) {
          $domain_id = $domain->getDomainId();
          $is_exists = \Drupal::service('path.alias_storage')
            ->aliasExistsByDomain($alias, $element['langcode']['#value'], $element['source']['#value'], $domain_id);
          if ($is_exists) {
            $form_state->setError($element, t('The alias is already in use by content available to all affiliates.'));
            break;
          }
        }
      }
      else if ($domain_values = $form_state->getValue(DOMAIN_ACCESS_FIELD)) {
        // If domains are checked, check existence of alias on each domain.
        foreach ($domain_values as $domain_value) {
          if ($domain = \Drupal::service('domain.loader')->load($domain_value['target_id'])) {
            // Validate that the submitted alias does not exist yet.
            $is_exists = \Drupal::service('path.alias_storage')
              ->aliasExistsByDomain($alias, $element['langcode']['#value'], $element['source']['#value'], $domain->getDomainId());
            if ($is_exists) {
              $form_state->setError($element, t('The alias is already in use on :domain.', [':domain' => $domain->get('name')]));
            }
          }
        }
      }
    }

    if ($alias && $alias[0] !== '/') {
      $form_state->setError($element, t('The alias needs to start with a slash.'));
    }
  }
}
