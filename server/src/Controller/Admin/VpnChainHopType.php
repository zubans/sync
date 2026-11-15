<?php

namespace App\Controller\Admin;

use App\Entity\VpnChainHop;
use App\Entity\VpnConnection;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Поля одного шага цепочки CEP в форме EasyAdmin CollectionField.
 *
 * @extends AbstractType<VpnChainHop>
 */
final class VpnChainHopType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('position', IntegerType::class, [
                'label' => 'Шаг №',
                'attr' => ['min' => 1, 'max' => 32],
                'empty_data' => '1',
            ])
            ->add('connection', EntityType::class, [
                'class' => VpnConnection::class,
                'label' => 'Подключение / узел',
                'placeholder' => 'Выберите подключение…',
                'query_builder' => static fn (EntityRepository $repo) => $repo->createQueryBuilder('c')
                    ->orderBy('c.name', 'ASC')
                    ->addOrderBy('c.id', 'ASC'),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => VpnChainHop::class,
        ]);
    }
}
