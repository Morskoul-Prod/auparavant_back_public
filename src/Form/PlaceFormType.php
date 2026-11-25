<?php

namespace App\Form;

use App\Entity\Location;
use App\Entity\Place;
use App\Enum\PlaceType as PlaceTypeEnum;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PlaceFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom du lieu',
                'attr' => [
                    'placeholder' => 'Ex: Tour Eiffel, Cathédrale Notre-Dame...',
                    'class' => 'form-control',
                ],
            ])
            ->add('type', EnumType::class, [
                'label' => 'Type de lieu',
                'class' => PlaceTypeEnum::class,
                'choice_label' => fn (PlaceTypeEnum $type) => $type->getLabel(),
                'attr' => [
                    'class' => 'form-control',
                ],
                'placeholder' => 'Sélectionnez un type de lieu',
            ])
            ->add('location', EntityType::class, [
                'label' => 'Localisation',
                'class' => Location::class,
                'choice_label' => function (Location $location) {
                    return $location->getStreet() . ' - ' . $location->getZipcode() . ' ' . $location->getCity();
                },
                'attr' => [
                    'class' => 'form-control',
                ],
                'placeholder' => 'Sélectionnez une localisation',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Place::class,
        ]);
    }
}
