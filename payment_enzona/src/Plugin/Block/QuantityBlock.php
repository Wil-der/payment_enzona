<?php

namespace Drupal\payment_enzona\Plugin\Block;

use Drupal\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Provides a 'QuantityBlock' block.
 *
 * @Block(
 *   id = "quantity_block",
 *   admin_label = @Translation("Bloque para añadir al carrito"),
 * )
 */
class QuantityBlock extends BlockBase
{

    protected $session;
    protected $messenger;

    // public function __construct(array $configuration, $plugin_id, $plugin_definition)
    // {
    //     parent::__construct($configuration, $plugin_id, $plugin_definition);
    // }


    // public static function create(ContainerInterface $container)
    // {
    //     // Crea una nueva instancia del bloque
    //     return new static(
    //         [],
    //         'quantity_block',
    //         [],
    //         $container->get('session'),
    //         $container->get('messenger')
    //     );
    // }

    public function build()
    {
        // Crea el formulario
        return \Drupal::formBuilder()->getForm([$this, 'quantityForm']);
    }

    public function quantityForm(array $form, FormStateInterface $form_state)
    {
        // Obtener el nodo actual
        $node = \Drupal::routeMatch()->getParameter('node');

        // Verificar si el nodo existe y tiene el campo 'quantity'
        if ($node && $node->hasField('field_quantity')) {
            $quantity_node = $node->get('field_quantity')->value; // Asegúrate de que este campo exista
        } else {
            // Manejar el caso donde no hay un nodo válido o no tiene el campo
            $quantity_node = 0;
        }

        // Definir el formulario
        $form['quantity'] = [
            '#type' => 'number',
            '#title' => $this->t('Cantidad'),
            '#default_value' => 1,
            '#min' => 1,
            '#max' => $quantity_node,
            '#step' => 1,
            '#attributes' => ['class' => ['quantity-input']],
        ];

        // Añadir botones para aumentar y disminuir la cantidad
        $form['controls'] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['quantity-controls']],
        ];

        $form['controls']['decrease'] = [
            '#type' => 'button',
            '#value' => $this->t('-'),
            '#attributes' => [
                'class' => ['decrease-button'],
                'onclick' => 'decreaseQuantity()',
                'style' => 'margin-right: 5px;',
            ],
        ];

        $form['controls']['increase'] = [
            '#type' => 'button',
            '#value' => $this->t('+'),
            '#attributes' => [
                'class' => ['increase-button'],
                'onclick' => 'increaseQuantity()',
                'style' => 'margin-left: 5px;',
            ],
        ];

        // Acciones del formulario
        $form['actions'] = [
            '#type' => 'actions',
            'add_to_cart' => [
                '#type' => 'submit',
                '#value' => $this->t('Añadir al carrito'),
                '#submit' => ['::addToCart'],
            ],
        ];

        $form['#attached']['library'][] = 'payment_enzona/quantity_block_styles';

        return $form;
    }

    public function addToCart(array &$form, FormStateInterface $form_state)
    {
        // Obtener la cantidad seleccionada y el ID del nodo
        $quantity = $form_state->getValue('quantity');
        $node_id = \Drupal::routeMatch()->getParameter('node')->id();

        // Guardar en la sesión
        $session = $this->session;
        $cart = $session->get('cart') ?: [];

        // Verificar si ya existe el producto en el carrito
        $product_exists = false;

        foreach ($cart as &$item) {
            if ($item['id'] == $node_id) {
                $item['quantity'] += (int)$quantity; // Incrementar cantidad si ya existe
                $product_exists = true;
                break;
            }
        }

        // Si no existe, añadir nuevo producto
        if (!$product_exists) {
            $cart[] = [
                'id' => (int)$node_id,
                'quantity' => (int)$quantity,
            ];
        }

        // Guardar el carrito actualizado en la sesión
        $session->set('cart', $cart);

        // Mensaje de confirmación usando el servicio de Messenger
        $this->messenger->addMessage($this->t('Se ha añadido @quantity unidades al carrito.', ['@quantity' => $quantity]));
    }
}
