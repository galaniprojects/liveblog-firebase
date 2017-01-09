<?php

namespace Drupal\liveblog_firebase\Controller;

use Drupal\liveblog_firebase\Exception\PluginNotActiveException;
use Drupal\liveblog_firebase\Plugin\LiveblogNotificationChannel\FirebaseNotificationChannel;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Template\TwigEnvironment;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class FirebaseController extends ControllerBase {

  /**
   * Drupal twig environment.
   *
   * @var TwigEnvironment
   */
  protected $twig;

  /**
   * FirebaseController constructor.
   *
   * @param TwigEnvironment $twig
   *    Drupal twig environment.
   */
  public function __construct(TwigEnvironment $twig) {
    $this->twig = $twig;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('twig')
    );
  }

  /**
   * Gets the Firebase Web App Manifest.
   *
   * @see https://developers.google.com/web/fundamentals/engage-and-retain/web-app-manifest/
   *
   * @return Response
   *    The http response object.
   */
  public function manifest() {
    $content = file_get_contents(drupal_get_path('module', 'liveblog_firebase') . '/js/app/manifest.json');
    $response = new JsonResponse($content);
    return $response;
  }

  /**
   * Gets the rendered Firebase Messaging Service Worker.
   *
   * @return Response
   *    The http response object.
   */
  public function serviceWorker() {
    try {
      $plugin = $this->getPlugin();
    }
    catch (PluginNotActiveException $e) {
      return new Response($e->getMessage(), Response::HTTP_FAILED_DEPENDENCY);
    }

    $template = $this->twig->load(
      drupal_get_path('module', 'liveblog_firebase') . '/js/app/firebase-messaging-sw.js.twig'
    );

    $context = array(
      'messagingSenderId' => $plugin->getConfigurationValue('sender_id'),
    );

    $response = new Response($template->render($context));
    $response->headers->set('Content-Type', 'application/javascript');
    return $response;
  }

  /**
   * Subscribe a user to a topic.
   *
   * @param string $token
   *    The users current registration token.
   * @param string $topic
   *    The topic id.
   *
   * @return Response
   *    The http response object.
   */
  public function subscribe($token, $topic) {
    $response = new Response();

    try {
      $plugin = $this->getPlugin();
      $client = $plugin->getClient();
      $client->addTopicSubscription($topic, $token);
    }
    catch (PluginNotActiveException $e) {
      return new Response($e->getMessage(), Response::HTTP_FAILED_DEPENDENCY);
    }

    return $response;
  }

  /**
   * Subscribe a user to a topic.
   *
   * @param string $token
   *    The users current registration token.
   * @param string $topic
   *    The topic id.
   *
   * @return Response
   *    The http response object.
   */
  public function unsubscribe($token, $topic) {
    $response = new Response();

    try {
      $plugin = $this->getPlugin();
      $client = $plugin->getClient();
      $client->removeTopicSubscription($topic, $token);
    }
    catch (PluginNotActiveException $e) {
      return new Response($e->getMessage(), Response::HTTP_FAILED_DEPENDENCY);
    }

    return $response;
  }

  /**
   * Gets the firebase plugin.
   *
   * @return FirebaseNotificationChannel
   *    The firebase notification channel.
   *
   * @throws PluginNotActiveException
   *    When the plugin is not active.
   */
  protected function getPlugin() {
    if ($this->config('liveblog.settings')->get('notification_channel') != FirebaseNotificationChannel::PLUGIN_ID) {
      throw new PluginNotActiveException();
    }

    /** @var FirebaseNotificationChannel $plugin */
    $plugin = $this->getNotificationChannelManager()->createActiveInstance();
    return $plugin;
  }

  /**
   * Gets the notification channel plugin manager.
   *
   * @return \Drupal\liveblog\NotificationChannel\NotificationChannelManager
   *   Notification channel plugin manager.
   */
  protected function getNotificationChannelManager() {
    return \Drupal::service('plugin.manager.liveblog.notification_channel');
  }

}
