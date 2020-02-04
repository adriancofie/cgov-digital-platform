<?php

namespace Drupal\cgov_mail\Controller;

/**
 * @file
 * Contains \Drupal\cgov_mail\Controller\MailAPIController.
 */

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Component\Utility\EmailValidatorInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Messenger\MessengerTrait;

/**
 * Controller routines for test_api routes.
 */
class MailAPIController extends ControllerBase {

  use StringTranslationTrait;
  use MessengerTrait;

  protected $emailValidator;

  protected $mailManager;

  protected $cgovMailConfig;

  protected $account;

  /**
   * {@inheritdoc}
   */
  public function __construct(EmailValidatorInterface $email_validator,
                              MailManagerInterface $mail_manager,
                              ConfigFactoryInterface $config_factory,
                              AccountInterface $account) {
    $this->emailValidator = $email_validator;
    $this->mailManager = $mail_manager;
    $this->cgovMailConfig = $config_factory->get('cgov_mail.settings');
    $this->account = $account;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('email.validator'),
      $container->get('plugin.manager.mail'),
      $container->get('config.factory'),
      $container->get('current_user')
    );
  }

  /**
   * Callback for `/FormEmailer` API method.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   The response.
   */
  public function handleForm(Request $request) {
    // Grab the referring URL in the event the
    // data fails validation, or the MailManager fails to send.
    $previousUrl = $request->server->get('HTTP_REFERER');

    // Initialize variables.
    $from = $to = $subject = $body = $redirect = $requiredFields = $splitFields = '';
    $errorList = [];
    $error_p = FALSE;

    $data = $request->request->all();
    foreach ($data as $key => $value) {
      switch ($key) {
        case 'submit.x':
          // Ignore.
          break;

        case 'submit.y':
          // Ignore.
          break;

        case '__from':
          $from = $value;
          if (!$this->emailValidator->isValid($from)) {
            $errorList[] = "Error: from email '" . $from . "' is invalid. Please go back and enter a valid email address.";
            $error_p = TRUE;
          }
          break;

        case '__subject':
          $subject = $value;
          break;

        case '__recipient';
          $to = $this->cgovMailConfig->get($value);
          // Set an error if a configured address was not found.
          if (($to === NULL) || ($to === '')) {
            $errorList[] = "Error: recipient '" . $value . "'' is not configured.";
            $error_p = TRUE;
          }
          break;

        case '__redirectto':
          $redirect = $value;
          break;

        case '__requiredfields':
          $requiredFields = str_replace(' ', '', trim($value));
          break;

        case '__splitFields':
          $splitFields = ',' . str_replace(' ', '', $value) . ',';
          break;

        // Recaptcha fields.
        case "g-recaptcha-response":
          $recaptchaResponseField = $value;
          break;

        default:
          if ($this->startsWith($key, '__linebreak')) {
            $body .= "\n";
          }
          else {
            // Only send "real" fields.
            if (!$this->startsWith($key, '__')) {
              $pos = strrpos($splitFields, $key);
              if ($pos === FALSE) {
                $temp_body = str_replace('_', ' ', $key);
                $body .= $temp_body . ': ' . $value . "\n";
              }
              else {
                $temp_key = str_replace('_', ' ', $key);
                $temp_value = str_replace(',', "\n\t\t", $value);
                $body .= $temp_key . ": \n\t\t" . urldecode($temp_value) . "\n";
              }
            }
          }
          break;
      }
    }

    // Check required fields.
    if ($requiredFields !== '') {
      $fields = explode(',', $requiredFields);
      foreach ($fields as $field) {
        if (($data[$field] === NULL) || trim($data[$field]) === '') {
          $errorList[] = 'Required field missing: ' . $field;
          $error_p = TRUE;
        }
      }
    }

    // Validate reCAPTCHA.
    // Google reCAPTCHA API secret key.
    $captcha_key = $this->cgovMailConfig->get('re-captcha');
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://www.google.com/recaptcha/api/siteverify");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
      'secret' => $captcha_key,
      'response' => $recaptchaResponseField,
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    $response = curl_exec($ch);
    curl_close($ch);
    $responseData = json_decode($response, TRUE);

    if (!$responseData['success'] === TRUE) {
      if ($responseData['error-codes'] === NULL) {
        $errorList[] = 'reCAPTCHA check not completed!';
      }
      else {
        foreach ($responseData['error-codes'] as $code) {
          $errorList[] = $code;
        }
      }
      $error_p = TRUE;
    }
    // If there was an error with the captcha return an error.
    if ($error_p) {
      foreach ($errorList as $error) {
        $this->messenger()
          ->addError('NOTICE: There was a problem sending your message
           and it was not sent. ERROR: ' . $error);
      }
      return new RedirectResponse($previousUrl);
    }
    else {
      // Send the message.
      $result = $this->sendMail($from, $to, $subject, $body);
      if ($result['result'] !== TRUE) {
        $this->messenger()
          ->addError($this->t('There was a problem sending your message and it was not sent.'));
        return new RedirectResponse($previousUrl);
      }
      else {
        // Redirect the user to the supplied uri.
        // drupal_set_message(t('Your message has been sent.'));.
        $response = new RedirectResponse($redirect);
      }
    }
    return $response;
  }

  /**
   * Sends the mail using the MailManager service.
   *
   * @param string $from
   *   From address.
   * @param string $to
   *   The email recipient.
   * @param string $subject
   *   The email subject.
   * @param string $body
   *   The body of the email.
   *
   * @return array
   *   The email success result.
   */
  private function sendMail($from, $to, $subject, $body) {
    $params['from'] = $from;
    $params['subject'] = $subject;
    $params['message'] = $body;
    $module = 'cgov_mail';
    $key = 'send_contact_form';
    $langcode = $this->account->getPreferredLangcode();
    $send = TRUE;

    $result = $this->mailManager->mail($module, $key, $to, $langcode, $params, NULL, $send);

    return $result;
  }

  /**
   * Helper function to determine if a string starts with another.
   *
   * @param string $haystack
   *   The string to search within.
   * @param string $needle
   *   The string to search for.
   *
   * @return bool
   *   Return true if found.
   */
  private function startsWith($haystack, $needle) {
    $length = strlen($needle);
    return (substr($haystack, 0, $length) === $needle);
  }

}
