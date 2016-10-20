<?php

namespace Drupal\domain_path;

use Drupal\pathauto\PathautoWidget as CorePathautoWidget;

/**
 * Extends the path auto widget.
 */
class PathautoWidget extends CorePathautoWidget {
  use PathWidgetValidatorTrait;
}
