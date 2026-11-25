<?php

namespace App\Form;

use App\Entity\User;
use App\Validator\PasswordPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isEdit = $options['is_edit'];

        $builder
            ->add('pseudonym', TextType::class, [
                'label' => 'Pseudonyme',
                'attr' => [
                    'placeholder' => 'Pseudonyme',
                    'class' => 'form-control',
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'attr' => [
                    'placeholder' => 'adresse@email.com',
                    'class' => 'form-control',
                ],
            ])
            ->add('roles', ChoiceType::class, [
                'label' => 'Rôles',
                'choices' => [
                    'Administrateur' => 'ROLE_ADMIN',
                    'Modérateur' => 'ROLE_MODERATOR',
                    'Utilisateur' => 'ROLE_USER',
                ],
                'multiple' => true,
                'expanded' => true,
                'attr' => [
                    'class' => 'form-check-group',
                ],
            ]);

        if (!$isEdit) {
            $builder->add('password', PasswordType::class, [
                'label' => 'Mot de passe',
                'required' => true,
                'constraints' => [new PasswordPolicy()],
                'attr' => [
                    'placeholder' => '••••••••',
                    'class' => 'form-control',
                ],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'is_edit' => false,
        ]);
    }
}
