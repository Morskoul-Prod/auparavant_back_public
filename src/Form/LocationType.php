<?php

namespace App\Form;

use App\Entity\Location;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class LocationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('street', TextType::class, [
                'label' => 'Rue',
                'attr' => [
                    'placeholder' => 'Numéro et nom de rue',
                    'class' => 'form-control',
                ],
            ])
            ->add('zipcode', IntegerType::class, [
                'label' => 'Code postal',
                'attr' => [
                    'placeholder' => '75000',
                    'class' => 'form-control',
                ],
            ])
            ->add('city', TextType::class, [
                'label' => 'Ville',
                'attr' => [
                    'placeholder' => 'Nom de la ville',
                    'class' => 'form-control',
                ],
            ])
            ->add('district', TextType::class, [
                'label' => 'Arrondissement',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Arrondissement (optionnel)',
                    'class' => 'form-control',
                ],
            ])
            ->add('department', TextType::class, [
                'label' => 'Département',
                'attr' => [
                    'placeholder' => 'Nom du département',
                    'class' => 'form-control',
                ],
            ])
            ->add('area', TextType::class, [
                'label' => 'Région',
                'attr' => [
                    'placeholder' => 'Nom de la région',
                    'class' => 'form-control',
                ],
            ])
            ->add('latitude', NumberType::class, [
                'label' => 'Latitude',
                'scale' => 8,
                'attr' => [
                    'placeholder' => '48.8566',
                    'class' => 'form-control',
                    'step' => 'any',
                ],
            ])
            ->add('longitude', NumberType::class, [
                'label' => 'Longitude',
                'scale' => 8,
                'attr' => [
                    'placeholder' => '2.3522',
                    'class' => 'form-control',
                    'step' => 'any',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Location::class,
        ]);
    }
}
