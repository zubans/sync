<?php

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

/**
 * Подключает доменную валидацию сервиса к формам создания и редактирования EasyAdmin
 * (ошибки возвращаются стандартным HTTP 422 прямо в форму).
 */
trait VpnCrudFormValidationTrait
{
    /** @return list<string> */
    abstract protected function validateCrudEntity(object $entity): array;

    public function createNewFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        return $this->attachCrudValidation(parent::createNewFormBuilder($entityDto, $formOptions, $context));
    }

    public function createEditFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        return $this->attachCrudValidation(parent::createEditFormBuilder($entityDto, $formOptions, $context));
    }

    private function attachCrudValidation(FormBuilderInterface $builder): FormBuilderInterface
    {
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $data = $event->getData();
            if (!is_object($data)) {
                return;
            }
            $form = $event->getForm();
            foreach ($this->validateCrudEntity($data) as $message) {
                $form->addError(new FormError($message));
            }
        });

        return $builder;
    }
}
