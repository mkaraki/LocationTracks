<?php

namespace App\Form;

use App\Entity\LocationSource;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class LocationSourceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $typeExpression = 'this.getParent().get("source_type").getData() === ';
        $owntracksRow = [
            'data-location-source-target' => 'field',
            'data-source-type' => (string) LocationSource::TYPE_OWNTRACKS,
        ];

        $builder
            ->add('name', TextType::class, [
                'label' => 'Source name',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 255)],
            ])
            ->add('source_type', ChoiceType::class, [
                'label' => 'Type',
                'choices' => [
                    'OwnTracks' => LocationSource::TYPE_OWNTRACKS,
                    'Google Timeline' => LocationSource::TYPE_GOOGLE_TIMELINE,
                ],
                'placeholder' => false,
                'attr' => [
                    'data-location-source-target' => 'type',
                    'data-action' => 'change->location-source#updateFields',
                ],
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('auth_username', TextType::class, [
                'label' => 'BASIC Auth Username',
                'required' => false,
                'attr' => ['autocomplete' => 'username', 'autocapitalize' => 'none', 'spellcheck' => 'false'],
                'row_attr' => $owntracksRow,
                'constraints' => [new Assert\When(
                    expression: $typeExpression.LocationSource::TYPE_OWNTRACKS,
                    constraints: [new Assert\NotBlank(), new Assert\Length(max: 255)],
                )],
            ])
            ->add('auth_password_hash', PasswordType::class, [
                'label' => 'BASIC Auth Password',
                'required' => false,
                'attr' => ['autocomplete' => 'new-password'],
                'row_attr' => $owntracksRow,
                'constraints' => [new Assert\When(
                    expression: $typeExpression.LocationSource::TYPE_OWNTRACKS,
                    constraints: [new Assert\NotBlank()],
                )],
            ])
            ->add('file', FileType::class, [
                'label' => 'Timeline JSON file',
                'help' => 'Select the Timeline.json file exported from Google Maps on Android.',
                'required' => false,
                'attr' => ['accept' => '.json,application/json'],
                'row_attr' => [
                    'data-location-source-target' => 'field',
                    'data-source-type' => (string) LocationSource::TYPE_GOOGLE_TIMELINE,
                ],
                'constraints' => [new Assert\When(
                    expression: $typeExpression.LocationSource::TYPE_GOOGLE_TIMELINE,
                    constraints: [new Assert\NotNull(message: 'Select a Timeline JSON file.'), new Assert\File(maxSize: '256Mi')],
                )],
            ])
            ->add('save', SubmitType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['attr' => ['data-controller' => 'location-source']]);
    }

    public function getBlockPrefix(): string
    {
        return 'form';
    }
}
