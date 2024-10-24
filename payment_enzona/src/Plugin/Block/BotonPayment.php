<?php

namespace Drupal\payment_enzona\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Provides a 'PagoBotonBlock' block.
 *
 * @Block(
 *   id = "pago_boton_block",
 *   admin_label = @Translation("Bloque para pagar"),
 *   category = @Translation("Custom"),
 * )
 */
class PagoBotonBlock extends BlockBase
{

  protected $session;

  public function __construct(array $configuration, $plugin_id, $plugin_definition)
  {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function build()
  {
    // Crear un formulario
    $form = [];

    // Añadir un campo oculto para los artículos del carrito
    $form['cart_data'] = [
      '#type' => 'hidden',
      '#value' => json_encode($this->getCartItems()), // Convertir a JSON para enviar
    ];

    // Botón de enviar
    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Comprar'),
        '#attributes' => [
          'class' => ['botonPagar'],
        ],
      ],
    ];

    // Adjuntar la biblioteca CSS
    $form['#attached']['library'][] = 'payment_enzona/boton_payment_block_styles';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    // Obtener los datos del carrito desde el campo oculto
    $cart_data_json = $form_state->getValue('cart_data');

    // Redirigir a la ruta de checkout con los parámetros del carrito
    return new Response('', 302, ['Location' => \Drupal\Core\Url::fromRoute('payment_enzona.checkout', ['cart' => $cart_data_json])->toString()]);
  }

  private function getCartItems()
  {
    $cart = $this->session->get('cart') ?: [];

    if (!isset($cart)) {
      $cart = [];
    }
    return $cart;
  }
}
