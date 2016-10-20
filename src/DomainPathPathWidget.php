<?php

namespace Drupal\domain_path;

use Drupal\path\Plugin\Field\FieldWidget\PathWidget;

/**
 * Extends the core path widget.
 */
class DomainPathPathWidget extends PathWidget {
  use DomainPathPathWidgetValidator;
}
