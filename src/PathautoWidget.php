<?php

namespace Drupal\domain_path;

use Drupal\pathauto\PathautoWidget as PathautoPathautoWidget;

/**
 * Extends the path auto widget.
 */
class PathautoWidget extends PathautoPathautoWidget {
  use PathWidgetValidatorTrait;
}
