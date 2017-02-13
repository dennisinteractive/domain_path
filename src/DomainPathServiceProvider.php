<?php

namespace Drupal\domain_path;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
* Modifies the pathauto services for generating and saving aliases.
*/
class DomainPathServiceProvider extends ServiceProviderBase {

  /**
  * {@inheritdoc}
  */
  public function alter(ContainerBuilder $container) {
    $modules = $container->getParameter('container.modules');
    if (isset($modules['pathauto'])) {
      // Extends the pathauto storage helper to store domain based paths.
      $definition = $container->getDefinition('pathauto.alias_storage_helper');
      $definition->setClass('Drupal\domain_path\AliasStorageHelper');
      // Extends the pathauto generator service to generate aliases based on domain lookups.
      $definition = $container->getDefinition('pathauto.generator');
      $definition->setClass('Drupal\domain_path\PathautoGenerator');
      // Extends the pathauto uniquifier service to check based on domain id.
      $definition = $container->getDefinition('pathauto.alias_uniquifier');
      $definition->setClass('Drupal\domain_path\AliasUniquifier');
    }
  }

}