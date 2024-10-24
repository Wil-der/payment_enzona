<?php

namespace Drupal\payment_enzona\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use GuzzleHttp\Client;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Drupal\paymenet_enzona\Other\Validations;

class PaymentController extends ControllerBase
{

  protected $session;
  protected $httpClient;
  protected $configFactory;
  protected $validate;

  public function __construct(SessionInterface $session, ConfigFactoryInterface $config_factory)
  {
    $this->session = $session;
    $this->httpClient = new Client();
    $this->configFactory = $config_factory;
    $this->validate = new Validations;
  }

  public function checkout(array $cart)
  {
    $node_ids = [];
    foreach ($cart as $item) {
      if (isset($item['id']) && isset($item['quantity'])) {
          $node_ids[] = $item['id'];
      }
  }

    $nodes = Node::loadMultiple($node_ids);

    if (empty($nodes)) {
      return new Response('Ningún nodo encontrado.', 404);
    }

    $errors = [];
    $itemPayload = [];
    $total = 0;

    foreach ($nodes as $node) {

      $validation = $this->validate->validateNode($node);

      if (!empty($validation)) {
        $errors[] = $validation;
      } else {
        $quantity = 0;
        foreach($cart as $item){
          if($item['id'] == $node->id()){
            $quantity = $item['quantity'];
            break;
          }
        }

        $itemPayload[] = [
          "name" => $node->get('field_name')->value,
          "description" => $node->get('field_description')->value,
          "quantity" => $quantity,
          "price" => $node->get('field_quantity')->value,
          "tax" => $node->get('field_tax')->value,
        ];
      }
    }

    if (!empty($errors)) {
      return new Response(implode('<br>', $errors), 400);
    }

    foreach ($itemPayload as $item) {
      $total += $item['price'] * $item['quantity'];
  }
    // Valores predeterminados
    $shipping = 0; // Monto del envío
    $tax = 0; // Monto de impuesto
    $discount = 0; // Descuento
    $tip = 0; // Propina

    // Preparar el payload para la API.
    $payload = [
      "description" => "Descripción del pago: " . implode(", ", array_column($itemPayload, 'name')),
      "currency" => "CUP", // Tipo de moneda por defecto
      "amount" => [
        "total" => $total,
        "details" => [
          "shipping" => $shipping,
          "tax" => $tax,
          "discount" => $discount,
          "tip" => $tip
        ]
      ],
      "items" => $itemPayload,
      "merchant_op_id" => '123456', // Identificador de la operación del comercio
      "invoice_number" => 'INV-' . time(), // Número de la factura
      "return_url" => 'http://example.com/return', // URL de retorno
      "cancel_url" => 'http://example.com/cancel', // URL de cancelación
      "terminal_id" => 'TERMINAL-001', // Identificador del terminal
      "buyer_identity_code" => '' // Código del comprador
    ];

    // Llamar al método para procesar el pago.
    $this->payMake($payload);
  }



  //OBTENER   token
  public function getToken()
  {
    $config = $this->configFactory->get('payment_enzona.settings');
    $public_key = $config->get('public_key');
    $secret_key = $config->get('secret_key');

    if (empty($public_key) || empty($secret_key)) {
      $url = Url::fromRoute('payment_enzona.key_form')->toString();
      return new RedirectResponse($url);
    }

    $url = 'https://apisandbox.enzona.net/token';

    try {
      //aki se parte pq no se conecta a la api
      $response = $this->httpClient->post($url, [
        'headers' => [
          'Authorization' => 'Basic ' . base64_encode($public_key . ':' . $secret_key),
          'Content-Type' => 'application/x-www-form-urlencoded',
        ],
        'form_params' => [
          'grant_type' => 'client_credentials',
        ],
      ]);
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

    $url = 'https://apisandbox.enzona.net/payment/payments';

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
  public function payCancel()
  {
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
