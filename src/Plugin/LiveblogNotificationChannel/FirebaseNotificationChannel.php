<?php

namespace Drupal\liveblog_firebase\Plugin\LiveblogNotificationChannel;

use sngrl\PhpFirebaseCloudMessaging\Client;
use sngrl\PhpFirebaseCloudMessaging\Message;
use sngrl\PhpFirebaseCloudMessaging\Recipient\Topic;

use Drupal\node\NodeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;

use Drupal\liveblog\Utility\Payload;
use Drupal\liveblog\Entity\LiveblogPost;
use Drupal\liveblog\NotificationChannel\NotificationChannelPluginBase;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Pusher.com notification channel.
 *
 * @LiveblogNotificationChannel(
 *   id = "liveblog_firebase",
 *   label = @Translation("Firebase Cloud Messaging"),
 *   description = @Translation("Firebase Cloud Messaging (FCM) delivers data messages with a limit of 4KB per message."),
 * )
 */
class FirebaseNotificationChannel extends NotificationChannelPluginBase {

  /**
   * Maximum allowed payload size.
   *
   * @see https://firebase.google.com/docs/cloud-messaging/concept-options
   */
  const FCM_DATA_LIMIT = 4096;

  /**
   * The firebase client.
   *
   * @var Client
   */
  protected $client;

  /**
   * Constructs an EntityForm object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $config_factory, $entity_type_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['server_key'] = [
      '#type' => 'textarea',
      '#title' => t('Server key'),
      '#required' => TRUE,
      '#default_value' => !empty($this->configuration['server_key']) ? $this->configuration['server_key'] : '',
      '#description' => t('A server key that authorizes your app server for access to Google services, including sending messages via Firebase Cloud Messaging. You obtain the server key when you create your Firebase project. You can view it in the <a href="//console.firebase.google.com/project/_/settings/cloudmessaging"> Cloud Messaging</a> tab of the Firebase console <strong>Settings</strong> pane.'),
    ];

    $form['sender_id'] = [
      '#type' => 'textfield',
      '#title' => t('Sender ID'),
      '#required' => TRUE,
      '#default_value' => !empty($this->configuration['sender_id']) ? $this->configuration['sender_id'] : '',
      '#description' => t('A unique numerical value created when you create your Firebase project, available in the <a href="//console.firebase.google.com/project/_/settings/cloudmessaging"> Cloud Messaging</a> tab of the Firebase console <strong>Settings</strong> pane. The sender ID is used to identify each app server that can send messages to the client app.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);

    if (!class_exists(Client::class)) {
      $form_state->setErrorByName('plugin', t('The "@class" class was not found. Please make sure you have included the <a href="https://github.com/sngrl/php-firebase-cloud-messaging">PHP API for Firebase Cloud Messaging</a>.', array('@class' => Client::class)));
    }

    $settings = $form_state->getValue('plugin_settings');

    $client = $this->getClient();
    $client->setApiKey($settings['server_key']);

    $message = new Message();
    $message->addRecipient(new Topic('_test'));
    $message->setJsonKey('dry_run', TRUE);

    try {
      $client->send($message);
    }
    catch (\GuzzleHttp\Exception\ClientException $e) {
      $form_state->setErrorByName('plugin', 'Failed to establish a connection. Invalid server key.');
    }
  }

  /**
   * {@inheritdoc}
   *
   * @return Client
   *   The notification channel client.
   */
  public function getClient() {
    if ($this->client) {
      return $this->client;
    }

    $server_key = $this->getConfigurationValue('server_key');

    $client = new Client();
    $client->setApiKey($server_key);
    $client->injectGuzzleHttpClient(new \GuzzleHttp\Client());

    $this->client = $client;

    return $this->client;
  }

  /**
   * {@inheritdoc}
   */
  public function triggerLiveblogPostEvent(LiveblogPost $liveblog_post, $event) {
    $client = $this->getClient();
    $data = Payload::create($liveblog_post)->getRenderedPayload();

    $message = new Message();
    $message->setPriority('high');
    $message->setTimeToLive(0);
    $message->addRecipient(new Topic(self::createTopicId($liveblog_post->getLiveblog())));
    $message->setData($data);

    // @TODO
    // $response = $client->send($message);
    // $status = $response->getStatusCode();
    $status = 200;

    /** @see https://firebase.google.com/docs/cloud-messaging/http-server-ref#error-codes */
    switch ($status) {
      // Message was processed successfully. The response body will contain
      // more details about the message status.
      case 200:
        break;

      // Indicates that the request could not be parsed as JSON, or it
      // contained invalid fields.
      case 400:
        break;

      // There was an error authenticating the sender account.
      case 401:
        break;

      // Indicate that there was an internal error in the FCM connection server
      // while trying to process the request, or that the server is temporarily
      // unavailable (for example, because of timeouts).
      // Sender must retry later, honoring any Retry-After header included in
      // the response. Application servers must implement exponential back-off.
      case 500:
      case 502:
      case 503:
      case 504:
        break;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateLiveblogPostForm(array &$form, FormStateInterface $form_state, LiveblogPost $liveblog_post) {
    $data = Payload::create($liveblog_post)->getRenderedPayload();
    // Add margin for missing id since the entity is not saved yet.
    $size = strlen(json_encode($data)) + 11;

    if ($size > self::FCM_DATA_LIMIT) {
      $form_state->setErrorByName('data', sprintf("Payload is to big: %d bytes (maximum of %d bytes allowed)", $size, self::FCM_DATA_LIMIT));
    }
  }

  /**
   * Creates a unique topic identifier generated from the liveblog.
   *
   * @param NodeInterface $liveblog
   *    The live blog post.
   *
   * @return string
   *    The unique topic id.
   */
  public static function createTopicId(NodeInterface $liveblog) {
    return "liveblog-{$liveblog->id()}";
  }

}
