<?php

namespace Drupal\domain_path;

use Drupal\path\Plugin\Field\FieldWidget\PathWidget as CorePathWidget;

/**
 * Extends the core path widget.
 */
class PathWidget extends CorePathWidget {
  use PathWidgetValidatorTrait;
}
