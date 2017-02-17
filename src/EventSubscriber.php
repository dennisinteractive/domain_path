<?php

namespace Drupal\domain_path;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\default_content\Event\DefaultContentEvents;
use Drupal\default_content\Event\ImportEvent;


/**
 * Subscribe to default_content.import
 */
class EventSubscriber implements EventSubscriberInterface {
  /**
   * {@inheritdoc}
   */
  static function getSubscribedEvents() {
    $events[DefaultContentEvents::IMPORT][] = array('onDefaultContentImport');
    return $events;
  }

  /**
   * Reacts to a Default Content import event.
   *
   * @param ImportEvent $event
   */
  function onDefaultContentImport(ImportEvent $event) {
    // Populate some of the imported Nodes with aliases.
    $url_aliases = [
      '2a8f861a-6975-43d6-abf4-efab6a8667a7' => '/test-content/homepage',
      '7c176b7f-f391-4d44-89a6-b593d7185264' => '/test-content/faq',
      'e818e8b8-b057-48c8-8eba-7c8d3ac52491' => '/test-content/basic-page',
      'd3b81fe0-0a5b-4123-b7ac-06d30e48ae52' => '/test-content/subscriptions-page',
      'e37eb023-4c95-423c-9ccb-fb94b320e882' => '/test/domain-page-test-autoexpressuk-vantage',
      '8cb7ba9d-3825-4c45-88c8-70a628cf00ed' => '/test/domain-page-test-samedomain',
      '2c24a28a-fdb4-4c98-8118-7c845e6b0f3d' => '/test/domain-page-test-samedomain',
      'ff24ef47-9f8e-43f1-b10c-df0c2e0d71ed' => '/test/domain-page-test-changeme',
    ];

    // Go through the imported content and assign some aliases
    $entities = $event->getImportedEntities();
    foreach ($entities as $entity) {
      $uuid = $entity->uuid();
      if (isset($url_aliases[$uuid])) {
        \Drupal::service('path.alias_storage')->save('/' . $entity->toUrl()->getInternalPath(), $url_aliases[$uuid]);
        \Drupal::logger('DomainAlias')->notice('Set Alias for entity: ' . $entity->id());
      }
    }
  }
}
