<?php

namespace Drupal\simplenews_decoupled\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\simplenews\Entity\Subscriber;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\simplenews\Subscription\SubscriptionStorageInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Component\Serialization\Json;
use Drupal\simplenews\Entity\Newsletter;
use Drupal\simplenews\Subscription\SubscriptionManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Provides POST/GET endpoints for subscribing/.
 */
class SubscribeController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * Subscriber Storage.
   *
   * @var \Drupal\simplenews\Subscription\SubscriptionStorage
   */
  protected $subscriptionStorage;

  /**
   * The subscription manager.
   *
   * @var \Drupal\simplenews\Subscription\SubscriptionManagerInterface
   */
  protected $subscriptionManager;

  /**
   * The Mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(SubscriptionStorageInterface $subscription_storage, SubscriptionManagerInterface $subscription_manager, MailManagerInterface $mail_manager) {
    $this->subscriptionStorage = $subscription_storage;
    $this->subscriptionManager = $subscription_manager;
    $this->mailManager = $mail_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')->getStorage('simplenews_subscriber'),
      $container->get('simplenews.subscription_manager'),
      $container->get('plugin.manager.mail')
    );
  }

  /**
   * Callback for subscribe action.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Simple json response to indicate the status of the subscription.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Exception
   */
  public function createSimplenewsSubscriber(Request $request) {
    $data = Json::decode($request->getContent());

    // @todo Dependency injection.
    if (!$data['email'] || !\Drupal::service('email.validator')->isValid($data['email'])) {
      return new JsonResponse([
        'message' => 'Invalid or missing E-Mail.',
      ], 500);
    }

    if (!$data['newsletterId']) {
      return new JsonResponse([
        'message' => 'You must provide the newsletter id to subscribe to.',
      ], 500);
    }

    // Check if subscriber with mail is already created.
    $subscriber = $this->subscriptionStorage->loadByProperties(["mail" => $data['email']]);

    if (!count($subscriber)) {
      $subscriber = Subscriber::create([
        'mail' => $data['email'],
      ]);
      $subscriber->save();
    }

    if (is_array($data['newsletterId'])) {
      foreach ($data['newsletterId'] as $newsletter) {
        $this->subscriptionManager->subscribe($data['email'], $newsletter, TRUE, 'simplenews_decoupled');
        $newsletters[] = Newsletter::load($newsletter)->name;
      }
    }
    else {
      $this->subscriptionManager->subscribe($data['email'], $data['newsletterId'], TRUE, 'simplenews_decoupled');
      $newsletters = [Newsletter::load($data['newsletterId'])->name];
    }

    $this->subscriptionManager->sendConfirmations();

    return new JsonResponse([
      'response' => $data['email'] . ' was subscribed to the newsletter(s) ' . implode(', ', $newsletters),
    ], 200);
  }

  /**
   * Callback for the subscription confirmation.
   *
   * @param string $action
   *   The action, eg 'subscribe'.
   * @param int $snid
   *   The subscriber id.
   * @param string $newsletter_id
   *   The newsletter.
   * @param int $timestamp
   *   The timestamp.
   * @param string $hash
   *   The hash.
   * @param bool $immediate
   *   Not yet used.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Json response to describe if success or fail.
   */
  public function confirmSimplenewsSubscriber($action, $snid, $newsletter_id, $timestamp, $hash, $immediate = FALSE) {
    // @todo Dependency injection.
    $config = \Drupal::config('simplenews.settings');
    $newsletter = Newsletter::load($newsletter_id);

    // @todo Check if subscriber is already confirmed.
    $subscriber = Subscriber::load($snid);
    if ($subscriber && $hash == simplenews_generate_hash($subscriber->getMail(), $action, $timestamp)) {
      // If the hash is valid but timestamp is too old, display form to request
      // a new hash.
      // @todo Dependency injection.
      if ($timestamp < \Drupal::time()->getRequestTime() - $config->get('hash_expiration') && $action !== 'remove') {
        return new JsonResponse([
          'success' => FALSE,
          // @todo Use $this->t().
          'message' => 'Der benutzte Link ist leider abgelaufen. Bitte melden Sie sich erneut für den Newsletter an.',
        ], 403);
      }

      if ($action == 'remove') {
        $this->subscriptionManager->unsubscribe($subscriber->getMail(), $newsletter_id, FALSE, 'simplenews_decoupled');

        return new JsonResponse([
          'success' => TRUE,
          // @todo Use $this->t().
          'message' => 'Sie wurden erfolgreich von der Abonnentenliste von ' . $newsletter->name . ' abgemeldet.',
        ], 200);
      }
      elseif ($action == 'add') {
        $this->subscriptionManager->subscribe($subscriber->getMail(), $newsletter_id, FALSE, 'simplenews_decoupled');

        $newsletter = Newsletter::load($newsletter_id);

        return new JsonResponse([
          'success' => TRUE,
          // @todo Use $this->t().
          'message' => 'Sie wurden erfolgreich zu der Abonnentenliste von ' . $newsletter->name . ' hinzugefügt.',
        ], 200);
      }
    }

    return new JsonResponse([
      'success' => FALSE,
      // @todo Use $this->t().
      'message' => 'Es ist leider etwas schief gelaufen. Bitte versuchen Sie es erneut.',
    ], 500);
  }

  /**
   * Confirm a combined subscription.
   *
   * @param int $snid
   *   The subscriber id.
   * @param int $timestamp
   *   The timestamp.
   * @param string $hash
   *   The hash.
   * @param bool $immediate
   *   Not yet used.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Json response to describe if success or fail.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function confirmSimplenewsSubscriberCombined($snid, $timestamp, $hash, $immediate = FALSE) {
    $config = $this->config('simplenews.settings');

    $subscriber = Subscriber::load($snid);

    // Redirect and display message if no changes are available.
    if ($subscriber && !$subscriber->getChanges()) {
      return new JsonResponse([
        'success' => TRUE,
        'message' => $this->t('All changes to your subscriptions where already applied. No changes made.'),
      ], 200);
    }

    if ($subscriber && $hash == simplenews_generate_hash($subscriber->getMail(), 'combined' . serialize($subscriber->getChanges()), $timestamp)) {
      // If the hash is valid but timestamp is too old, display form to request
      // a new hash.
      // @todo Dependency injection.
      if ($timestamp < \Drupal::time()->getRequestTime() - $config->get('hash_expiration')) {
        return new JsonResponse([
          'success' => FALSE,
          // @todo Use $this->t().
          'message' => 'Der benutzte Link ist leider abgelaufen. Bitte melden Sie sich erneut für den Newsletter an.',
        ], 403);
      }

      // Redirect and display message if no changes are available.
      foreach ($subscriber->getChanges() as $newsletter_id => $action) {
        if ($action == 'subscribe') {
          // @todo When using this code, only the first newsletter gets
          //   subscribed, all other remain 'unconfirmed'.
          // $this->subscriptionManager
          // ->subscribe($subscriber->getMail(), $newsletter_id, FALSE);
          $subscriber->subscribe($newsletter_id, SIMPLENEWS_SUBSCRIPTION_STATUS_SUBSCRIBED, 'simplenews_decoupled');
        }
        elseif ($action == 'unsubscribe') {
          // $this->subscriptionManager
          // ->unsubscribe($subscriber->getMail(), $newsletter_id, FALSE);
          $subscriber->subscribe($newsletter_id, SIMPLENEWS_SUBSCRIPTION_STATUS_SUBSCRIBED, 'simplenews_decoupled');
        }
      }

      // Clear changes.
      $subscriber->setChanges([]);
      $subscriber->save();

      return new JsonResponse([
        'success' => TRUE,
        'message' => $this->t('Subscription changes confirmed for %user.', ['%user' => $subscriber->getMail()]),
      ], 200);
    }
    throw new NotFoundHttpException();
  }

}
