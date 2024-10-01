<?php

namespace Drupal\payment_enzona\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

class KeyForm extends ConfigFormBase
{
    public function getFormId()
    {
        return 'key_form';
    }

    protected function getEditableConfigNames()
    {
        return ['payment_enzona.settings'];
    }

    public function buildForm(array $form, FormStateInterface $form_state)
    {
        $config = $this->config('payment_enzona.settings');

        $form['public_key'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Clave Pública'),
            '#required' => TRUE,
            '#default_value' => $config->get('public_key'),
        ];

        $form['secret_key'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Clave Privada'),
            '#required' => TRUE,
            '#default_value' => $config->get('secret_key'),
        ];

        $form['submit'] = [
            '#type' => 'submit',
            '#value' => $this->t('Guardar'),
        ];

        return $form;
    }

    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        $this->config('payment_enzona.settings')
            ->set('public_key', $form_state->getValue('public_key'))
            ->set('secret_key', $form_state->getValue('secret_key'))
            ->save();

        $url = Url::fromRoute('payment_enzona.enviar_api');

        $form_state->setRedirectUrl($url);
    }
}
