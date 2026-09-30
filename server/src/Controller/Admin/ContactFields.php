<?php

namespace App\Controller\Admin;

use App\Entity\Contact;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Поля контакта, общие для «Контактов», «Семейных контактов» и корзины. Пустое значение EasyAdmin
 * показывает бейджем «Null», поэтому для показа — строковое поле + formatValue с «—».
 */
final class ContactFields
{
    /**
     * Фото: миниатюра в списке, крупно — на карточке. В HTML попадает только SHA-256 (hex), экранировать нечего.
     *
     * @param \Closure(string): string $url адрес фото по SHA-256
     */
    public static function photo(\Closure $url, string $pageName): TextField
    {
        $size = $pageName === Crud::PAGE_DETAIL ? 240 : 40;

        return TextField::new('uuid', 'Фото')
            ->formatValue(static fn ($value, Contact $contact) => $contact->getPhotoSha256() === null
                ? '—'
                : \sprintf(
                    '<a href="%1$s" target="_blank"><img src="%1$s" alt="" style="width:%2$dpx;height:%2$dpx;object-fit:cover;border-radius:%3$s"></a>',
                    $url($contact->getPhotoSha256()),
                    $size,
                    $size > 40 ? '12px' : '50%',
                ))
            ->renderAsHtml()
            ->setSortable(false)
            ->hideOnForm();
    }

    /** @return iterable<TextField> показ и поле формы */
    public static function birthday(): iterable
    {
        yield TextField::new('uuid', 'День рождения')
            ->formatValue(static fn ($value, Contact $contact) => $contact->getBirthday() ?? '—')
            ->setSortable(false)
            ->hideOnForm();
        yield TextField::new('birthday', 'День рождения')
            ->setRequired(false)
            ->setHelp('ГГГГ-ММ-ДД, без года — --ММ-ДД (например, --05-17).')
            ->onlyOnForms();
    }
}
