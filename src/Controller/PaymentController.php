<?php

namespace Drupal\payment_enzona\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use GuzzleHttp\Client;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class PaymentController extends ControllerBase
{

  protected $session;
  protected $httpClient;
  protected $configFactory;

  public function __construct(SessionInterface $session, ConfigFactoryInterface $config_factory)
  {
    $this->session = $session;
    $this->httpClient = new Client();
    $this->configFactory = $config_factory;
  }

  public function checkout(Request $request)
  {
    $errors = [];
    $data = json_decode($request->getContent(), TRUE);

    $description = $data['description'] ?? '';
    if (empty($description)) {
      $errors[] = 'La descripción es obligatoria.';
    }

    $currency = $data['currency'] ?? '';
    if (empty($currency)) {
      $errors[] = 'La moneda es obligatoria.';
    }

    $total = $data['amount']['total'] ?? '';
    if (empty($total) || !is_numeric($total)) {
      $errors[] = 'El total debe ser un número válido.';
    }

    $shipping = $data['amount']['details']['shipping'] ?? 0;
    $tax = $data['amount']['details']['tax'] ?? 0;
    $discount = $data['amount']['details']['discount'] ?? 0;
    $tip = $data['amount']['details']['tip'] ?? 0;

    $itemName = $data['items'][0]['name'] ?? '';
    if (empty($itemName)) {
      $errors[] = 'El nombre del ítem es obligatorio.';
    }

    $itemDescription = $data['items'][0]['description'] ?? '';
    $itemQuantity = $data['items'][0]['quantity'] ?? '';
    if (empty($itemQuantity) || !is_numeric($itemQuantity) || $itemQuantity <= 0) {
      $errors[] = 'La cantidad del ítem debe ser un número positivo.';
    }

    $itemPrice = $data['items'][0]['price'] ?? '';
    if (empty($itemPrice) || !is_numeric($itemPrice)) {
      $errors[] = 'El precio del ítem debe ser un número válido.';
    }

    $merchantOpId = $data['merchant_op_id'] ?? '';
    $invoiceNumber = $data['invoice_number'] ?? '';
    $terminalId = $data['terminal_id'] ?? '';


    if (!empty($errors)) {
      return new Response(implode('<br>', $errors), 400);
    }

    $payload = array(
      "description" => $description,
      "currency" => $currency,
      "amount" => array(
        "total" => $total,
        "details" => array(
          "shipping" => $shipping,
          "tax" => $tax,
          "discount" => $discount,
          "tip" => $tip
        )
      ),
      "items" => array(
        array(
          "name" => $itemName,
          "description" => $itemDescription,
          "quantity" => $itemQuantity,
          "price" => $itemPrice,
          "tax" => $tax
        )
      ),
      "merchant_op_id" => $merchantOpId,
      "invoice_number" => $invoiceNumber,
      "return_url" => 'url de return',
      "cancel_url" => 'url de cancelar',
      "terminal_id" => $terminalId,
      "buyer_identity_code" => ""
    );

    $this->payMake($payload);
  }

  //OBTENER   token
  public function getToken()
  {
    dump('entro a getToken');
    $config = $this->configFactory->get('payment_enzona.settings');
    $public_key = $config->get('public_key');
    $secret_key = $config->get('secret_key');

    if (empty($public_key) || empty($secret_key)) {
      $url = Url::fromRoute('payment_enzona.key_form')->toString();
      return new RedirectResponse($url);
    }

    $url = 'https://apisandbox.enzona.net/token';

    try {
      dump('entro al try');
      $response = $this->httpClient->post($url, [
        'headers' => [
          'Authorization' => 'Basic ' . base64_encode($public_key . ':' . $secret_key),
          'Content-Type' => 'application/x-www-form-urlencoded',
        ],
        'form_params' => [
          'grant_type' => 'client_credentials',
        ],
      ]);
dump('se conecto con enzona');
      if ($response->getStatusCode() === 200) {
        $data = json_decode($response->getBody(), TRUE);
        return $data['access_token'];
      } else {
        \Drupal::logger('payment_enzona')->error('Error al obtener el token. Código de estado: ' . $response->getStatusCode());
        return NULL;
      }
    } catch (\Exception $e) {
      \Drupal::logger('payment_enzona')->error('Error de conexión con EnZona: ' . $e->getMessage());
      return NULL;
    }
  }


  //crear pago
  public function payMake($payload)
  {
    $token = $this->getToken();

    if (!$token) {
      return new JsonResponse(['Error' => 'No se pudo obtener el token de autenticación.'], 500);
    }

    $url = 'https://apisandbox.enzona.net/payment/v1.0/payments';

    try {
      $response = $this->httpClient->post($url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $token,
          'Content-Type' => 'application/json',
        ],
        'json' => $payload,
      ]);

      $responseBody = json_decode($response->getBody(), true);

      if ($response->getStatusCode() == 200) {
        $this->session->set('transaction_uuid', $responseBody['transaction_uuid']);

        foreach ($responseBody['links'] as $link) {
          if ($link['method'] === 'REDIRECT') {
            return new RedirectResponse($link['href']);
          }
        }
      } else {
        \Drupal::logger('payment_enzona')->error('Error al crear el pago. Código de estado: ' . $response->getStatusCode());
        return new JsonResponse(['Error' => 'Error al crear el pago.'], 500);
      }
    } catch (\Exception $e) {
      \Drupal::logger('payment_enzona')->error('Excepción al crear el pago: ' . $e->getMessage());
      return new JsonResponse(['Error' => 'Error al realizar el pago.'], 500);
    }
  }


  //completar pago
  public function paySuccess()
  {
    $transaction_uuid = $this->session->get('transaction_uuid');

    if (!$transaction_uuid) {
      return new JsonResponse(['error' => 'No se encontró el UUID de la transacción'], 400);
    }

    $url = 'https://apisandbox.enzona.net/payments/' . $transaction_uuid . '/complete';

    try {
      $response = $this->httpClient->post($url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $this->getToken(),
          'Content-Type' => 'application/json',
        ],
      ]);

      if ($response->getStatusCode() === 200) {
        $responseBody = json_decode($response->getBody(), true);
        return new JsonResponse(['Succes' => 'Pago efectuado con éxito, número de factura: ' . $responseBody['invoice_number']], 200);
      } else {
        \Drupal::logger('payment_enzona')->error('Error al completar el pago. Código de estado: ' . $response->getStatusCode());
        return new JsonResponse(['Error' => 'Error al completar el pago.'], 500);
      }
    } catch (\Exception $e) {
      \Drupal::logger('payment_enzona')->error('Excepción al completar el pago: ' . $e->getMessage());
      return new JsonResponse(['Error' => 'Error al completar el pago.'], 500);
    }
  }


  //cancelar pago
  public function payCancel() {
    $transaction_uuid = $this->session->get('transaction_uuid');
  
    if (!$transaction_uuid) {
      return new JsonResponse(['Error' => 'No se encontró el UUID de la transacción'], 400);
    }
  
    $url = 'https://apisandbox.enzona.net/payments/' . $transaction_uuid . '/cancel';
  
    try {
      $response = $this->httpClient->post($url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $this->getToken(),
          'Content-Type' => 'application/json',
        ],
      ]);
  
      if ($response->getStatusCode() === 200) {
        return new JsonResponse(['Succes' => 'Pago cancelado con éxito'], 200);
      } else {
        \Drupal::logger('payment_enzona')->error('Error al cancelar el pago. Código de estado: ' . $response->getStatusCode());
        return new JsonResponse(['Error' => 'Error al cancelar el pago.'], 500);
      }
    } catch (\Exception $e) {
      \Drupal::logger('payment_enzona')->error('Excepción al cancelar el pago: ' . $e->getMessage());
      return new JsonResponse(['Error' => 'Error al cancelar el pago.'], 500);
    }
  }  
}
