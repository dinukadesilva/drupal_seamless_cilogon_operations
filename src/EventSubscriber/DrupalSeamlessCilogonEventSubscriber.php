<?php

namespace Drupal\drupal_seamless_cilogon\EventSubscriber;

use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Event Subscriber DrupalSeamlessCilogonEventSubscriber.
 */
class DrupalSeamlessCilogonEventSubscriber implements EventSubscriberInterface {

  const SEAMLESSCOOKIENAME = 'access_ci_sso';

  /**
   * Event handler for KernelEvents::REQUEST events, specifically to support
   * seamless login by checking if a non-authenticated user already has already
   * been through seamless login.
   *
   * Logic:
   *  - if user already authenticated and if there is no cookie, logout.
   *    They must have logged out on another ACCESS subdomain. 
   *    Otherwise return.
   *  - if cilogon_auth module not installed, just return
   *  - if the the seamless_cilogon cookie does not exist, just return
   *  - otherwise, redirect to CILogon.
   */
  public function onRequest(RequestEvent $event) {
    
    // $route_name = \Drupal::routeMatch()->getRouteName();
    // $msg = __FUNCTION__ . "() - just route = $route_name"
    //   . ' -- ' . basename(__FILE__) . ':' . __LINE__;
    // \Drupal::messenger()->addStatus($msg);
    // \Drupal::logger('seamless_cilogon')->debug($msg);

    // return;

    $seamless_debug = \Drupal::state()->get('drupal_seamless_cilogon.seamless_cookie_debug', TRUE);

    $seamless_login_enabled = \Drupal::state()->get('drupal_seamless_cilogon.seamless_login_enabled', TRUE);

    if ($seamless_debug) {
      $msg = __FUNCTION__ . "() ------- redirect to cilogon is "
        . ($seamless_login_enabled ? "ENABLED" : "DISABLED")
        . ' -- ' . basename(__FILE__) . ':' . __LINE__;
      \Drupal::messenger()->addStatus($msg);
      \Drupal::logger('seamless_cilogon')->debug($msg);
    }

    if (!$seamless_login_enabled) {
      return;
    }

    $cookie_name = \Drupal::state()->get('drupal_seamless_cilogon.seamlesscookiename', self::SEAMLESSCOOKIENAME);

    /*
        on https://test.support.access-ci.org/user/login?destination=/front-projects-nect, i see cookie with value 1,
        but the code below doesn't get it reliably
        onRequest() - $_COOKIE[access_ci_sso] = <not set> -- DrupalSeamlessCilogonEventSubscriber.php:64

        trying various ways to read the cookie -- now manually setting it
        in login hook
    */
    // $cookie_exists = NULL !== \Drupal::service('request_stack')->getCurrentRequest()->cookies->get($cookie_name);
    $cookie_exists = isset($_COOKIE[$cookie_name]);

    if ($seamless_debug) {
      $msg = __FUNCTION__ . "() - \$_COOKIE[$cookie_name] = "
        . ($cookie_exists ? print_r($_COOKIE[$cookie_name], TRUE) : ' <not set>')
        . ' -- ' . basename(__FILE__) . ':' . __LINE__;
      \Drupal::messenger()->addStatus($msg);
      \Drupal::logger('seamless_cilogon')->debug($msg);
    }

    // If the user is authenticated, no need to redirect to CILogin.
    if (\Drupal::currentUser()->isAuthenticated()) {

      $route_name = \Drupal::routeMatch()->getRouteName();

      // kint($route_name);

      if ($seamless_debug) {
        $msg = __FUNCTION__ . "() - user already authenticated"
          . ' -- ' . basename(__FILE__) . ':' . __LINE__;
        \Drupal::messenger()->addStatus($msg);
        \Drupal::logger('seamless_cilogon')->debug($msg);

        $timeofday=gettimeofday(); 
        $timestamp = sprintf("%s.%06d", date('Y-m-d H:i:s', $timeofday['sec']), $timeofday['usec']);

        $msg = __FUNCTION__ . "() - route_name = $route_name"
          . ' -- ' . basename(__FILE__) . ':' . __LINE__ . ' ' . $timestamp;
        \Drupal::messenger()->addStatus($msg);
        \Drupal::logger('seamless_cilogon')->debug($msg);
      }

      // Unless cookie doesn't exist. In this case, logout.
      if (!$cookie_exists && 
          $route_name !== 'user.logout' && 
          $route_name !== 'user.login' && 
          $route_name !== 'cilogon_auth.redirect_controller_redirect' && 
          verify_domain_is_asp()) {
        // $redirect = new RedirectResponse("/user/logout/");
        // $event->setResponse($redirect->send());
      }

      return;
    }

    // Don't attempt to redirect if the cilogon_auth module is not installed.
    $moduleHandler = \Drupal::service('module_handler');
    if (!$moduleHandler->moduleExists('cilogon_auth')) {
      return;
    }
    // If cookie is set, redirect to CILogon flow
    // if no cookie, do nothing, just return.
    if (!$cookie_exists) {
      return;
    }
    
    if ($seamless_debug) {
      $msg = __FUNCTION__ . "() - redirect to cilogon"
        . ' -- ' . basename(__FILE__) . ':' . __LINE__;
      \Drupal::messenger()->addStatus($msg);
      \Drupal::logger('seamless_cilogon')->debug($msg);
    }

    // Setup redirect to CILogon flow.
    // @todo could any of the following be moved to a constructor for this class?
    $container = \Drupal::getContainer();
    $client_name = 'cilogon';
    $config_name = 'cilogon_auth.settings.' . $client_name;
    $configuration = $container->get('config.factory')->get($config_name)->get('settings');
    $pluginManager = $container->get('plugin.manager.cilogon_auth_client.processor');
    $claims = $container->get('cilogon_auth.claims');
    $client = $pluginManager->createInstance($client_name, $configuration);
    $scopes = $claims->getScopes();
    // Not sure how this is used - following pattern in cilogon_auth/src/Form/CILogonAuthLoginForm.php.
    $_SESSION['cilogon_auth_op'] = 'login';
    $response = $client->authorize($scopes);
    $event->setResponse($response);
  }

  /**
   * Subscribe to onRequest events.  This allows checking if a CILogon redirect is needed any time
   * a page is requested.
   *
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [KernelEvents::REQUEST => 'onRequest'];
  }

}
