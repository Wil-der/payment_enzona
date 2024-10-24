<?php

namespace Drupal\paymenet_enzona\Other;

class Validations
{
    public function validateNode($node)
    {
        $errors = [];

        $itemName = $node->get('field_name')->value; // Campo de nombre del ítem.
        $itemQuantity = $node->get('field_quantity')->value; // Campo de cantidad.
        $itemPrice = $node->get('field_quantity')->value; // Campo de precio.


        if (empty($itemName)) {
            $errors[] = 'El nombre del ítem es obligatorio para el nodo ' . $node->id();
        }
        if (empty($itemQuantity) || !is_numeric($itemQuantity) || $itemQuantity <= 0) {
            $errors[] = 'La cantidad del ítem debe ser un número positivo para el nodo ' . $node->id();
        }
        if (empty($itemPrice) || !is_numeric($itemPrice)) {
            $errors[] = 'El precio del ítem debe ser un número válido para el nodo ' . $node->id();
        }

        return $errors;
    }
}
