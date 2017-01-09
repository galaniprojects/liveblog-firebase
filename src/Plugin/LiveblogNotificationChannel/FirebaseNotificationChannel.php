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

use Psr\Log\LoggerInterface;
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
   * The plugin id which is set in liveblog.settings.notification_channel.
   */
  const PLUGIN_ID = 'liveblog_firebase';

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
   * The logger.
   *
   * @var LoggerInterface
   */
  protected $logger;

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
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $config_factory, $entity_type_manager);
    $this->logger = \Drupal::logger('liveblog_firebase');
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
    $message = $this->createMessage($liveblog_post, $event);
    $size = $this->estimateMessageSize($message);

    if ($size > self::FCM_DATA_LIMIT) {
      $error_msg = sprintf("Payload is to big: %d bytes (maximum of %d bytes allowed)", $size, self::FCM_DATA_LIMIT);
      drupal_set_message($error_msg, 'error');
      return;
    }

    $status = NULL;
    $result = NULL;
    $user_error_msg = t('An error occured while sending the data to the Firecloud Server, please check the admin log for more info.');

    try {
      $client = $this->getClient();
      $response = $client->send($message);
      $result = $response->getBody()->getContents();
      $result = json_decode($result);
    }
    catch (\Exception $e) {
      drupal_set_message($user_error_msg, 'error');
      $this->logger->error($e->getMessage());
      return;
    }

    if (isset($result->error)) {
      $this->logger->error(sprintf('Message ID: %s Error: %s', isset($result->message_id) ? $result->message_id : "-", $result->error));
      drupal_set_message($user_error_msg, 'error');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateLiveblogPostForm(array &$form, FormStateInterface $form_state, LiveblogPost $liveblog_post) {
    $message = $this->createMessage($liveblog_post, 'edit');
    $size = $this->estimateMessageSize($message);

    if ($size > self::FCM_DATA_LIMIT) {
      $error_msg = sprintf("Payload is to big: %d bytes (maximum of %d bytes allowed)", $size, self::FCM_DATA_LIMIT);
      $form_state->setErrorByName('data', $error_msg);
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

  /**
   * Creates the message object which will be sent to the FCM server.
   *
   * @param LiveblogPost $liveblog_post
   *    The live blog post.
   * @param string $event
   *    The event type, e.g.: `edit`, `add`.
   *
   * @return Message
   *    The message object.
   */
  protected function createMessage(LiveblogPost $liveblog_post, $event) {
    $topic_id = self::createTopicId($liveblog_post->getLiveblog());

    $data = Payload::create($liveblog_post)->getRenderedPayload();
    $data['event'] = $event;
    $data['topic_id'] = $topic_id;

    $message = new Message();
    $message->setPriority('high');
    $message->setTimeToLive(0);
    $message->addRecipient(new Topic($topic_id));
    $message->setData($data);

    return $message;
  }

  /**
   * Gets the estimated size for the message.
   *
   * @param Message $message
   *    The data message.
   *
   * @return int
   *    The estimated size of the message.
   */
  protected function estimateMessageSize(Message $message) {
    $data = [
      'headers' => [
        'Authorization' => sprintf('key=%s', $this->getConfigurationValue('server_key')),
        'Content-Type' => 'application/json',
      ],
      'body' => json_encode($message),
    ];

    // Add margin for id.
    $size = strlen(json_encode($data)) + 11;

    return $size;
  }

}
