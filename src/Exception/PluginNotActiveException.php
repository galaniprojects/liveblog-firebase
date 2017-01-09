<?php

namespace Drupal\liveblog_firebase\Exception;

/**
 * Plugin not active exception.
 */
class PluginNotActiveException extends \Exception {

  /**
   * Error message.
   *
   * @var string
   */
  protected $message = 'The notification channel for firebase is not activated.';

}
