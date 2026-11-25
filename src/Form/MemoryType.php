<?php

namespace App\Form;

use App\Entity\Location;
use App\Entity\Memory;
use App\Entity\Place;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MemoryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Titre',
                'required' => true,
                'attr' => [
                    'placeholder' => 'Titre du souvenir',
                    'class' => 'form-control',
                ],
            ])
            ->add('content', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Décrivez ce souvenir...',
                    'class' => 'form-control',
                    'rows' => 5,
                ],
            ])
            // Deux façons de dater un souvenir : date exacte (l'année en est déduite) ou année seule
            ->add('picture_date', DateType::class, [
                'label' => 'Date exacte',
                'widget' => 'single_text',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                ],
            ])
            ->add('year', IntegerType::class, [
                'label' => 'Année seule',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Ex: 1920',
                    'min' => Memory::MIN_YEAR,
                    'max' => date('Y'),
                ],
            ])
            ->add('user', EntityType::class, [
                'label' => 'Auteur',
                'class' => User::class,
                'choice_label' => function (User $user) {
                    return $user->getDisplayName();
                },
                'attr' => [
                    'class' => 'form-control',
                ],
            ])
            ->add('location', EntityType::class, [
                'label' => 'Localisation',
                'class' => Location::class,
                'choice_label' => function (Location $location) {
                    return $location->getStreet() . ' ' . $location->getZipcode() . ' ' . $location->getCity();
                },
                'attr' => [
                    'class' => 'form-control',
                ],
            ])
            ->add('place', EntityType::class, [
                'label' => 'Lieu',
                'class' => Place::class,
                'choice_label' => function (Place $place) {
                    return $place->getName() . ' - ' . $place->getType()->getLabel();
                },
                'attr' => [
                    'class' => 'form-control',
                ],
            ])
            // ✅ NOUVEAU : Collection de pictures avec VichUploader
            ->add('pictures', CollectionType::class, [
                'entry_type' => PictureType::class,
                'entry_options' => [
                    'label' => false,
                ],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false, // ✅ CRITIQUE : Force l'appel à addPicture()/removePicture()
                'label' => 'Photos',
                'prototype' => true,
                'attr' => [
                    'class' => 'pictures-collection',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Memory::class,
            'is_edit' => false, // ✅ Conservé pour différencier création/édition
        ]);
    }
}
