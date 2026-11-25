<?php

namespace App\Form;

use App\Entity\Picture;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Vich\UploaderBundle\Form\Type\VichFileType;

class PictureType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('imageFile', VichFileType::class, [
                'label' => 'Photo',
                'required' => false,  // ✅ false en édition
                'allow_delete' => false,
                'download_uri' => false,
                'attr' => [
                    'class' => 'form-control',
                    'accept' => 'image/*',
                ],
                'constraints' => [
                    new File([
                        'maxSize' => '10M',
                        'mimeTypes' => [
                            'image/jpeg',
                            'image/png',
                            'image/gif',
                            'image/webp',
                        ],
                        'mimeTypesMessage' => 'Veuillez choisir une image valide (JPEG, PNG, GIF ou WebP)',
                    ]),
                ],
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'Type de photo',
                'choices' => Picture::getTypeChoices(),
                'attr' => [
                    'class' => 'form-control',
                ],
                'required' => false,
            ])
            ->add('copyrightHolder', null, [
                'label' => 'Crédit photo',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Indiquez le nom du photographe ou de la source de l\'image',
                ],
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Picture::class,
            'is_edit' => false,
        ]);
    }
}
