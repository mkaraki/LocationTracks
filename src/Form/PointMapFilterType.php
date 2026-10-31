<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class PointMapFilterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('tz_sign', ChoiceType::class, [
                'label' => 'UTC offset',
                'choices' => ['+' => '+', '−' => '-'],
            ])
            ->add('tz_hours', IntegerType::class, [
                'label' => 'Hours',
                'attr' => ['min' => 0, 'max' => 14],
                'constraints' => [new Assert\NotNull(), new Assert\Range(min: 0, max: 14)],
            ])
            ->add('tz_minutes', IntegerType::class, [
                'label' => 'Minutes',
                'attr' => ['min' => 0, 'max' => 59],
                'constraints' => [new Assert\NotNull(), new Assert\Range(min: 0, max: 59)],
            ]);

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $this->addDates($event->getForm(), '+00:00');
            $event->setData([
                'tz_sign' => '+', 'tz_hours' => 0, 'tz_minutes' => 0,
                ...$this->today('+00:00'),
            ]);
        });

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
            $data = $event->getData();
            if (!is_array($data)) {
                return;
            }

            // Missing parameters have defaults; explicitly empty or malformed values remain invalid.
            $data += ['tz_sign' => '+', 'tz_hours' => '0', 'tz_minutes' => '0'];
            foreach (['tz_hours', 'tz_minutes'] as $name) {
                if (is_string($data[$name])) {
                    $data[$name] = trim($data[$name]);
                }
            }
            $offset = '+00:00';
            if (in_array($data['tz_sign'], ['+', '-'], true)
                && is_scalar($data['tz_hours']) && preg_match('/^[0-9]+$/D', (string) $data['tz_hours']) && $data['tz_hours'] <= 14
                && is_scalar($data['tz_minutes']) && preg_match('/^[0-9]+$/D', (string) $data['tz_minutes']) && $data['tz_minutes'] <= 59) {
                $offset = sprintf('%s%02d:%02d', $data['tz_sign'], $data['tz_hours'], $data['tz_minutes']);
            } else {
                $event->getForm()->addError(new FormError('Enter a valid UTC offset in whole hours and minutes.'));
            }

            $this->addDates($event->getForm(), $offset);
            foreach ($this->today($offset) as $name => $date) {
                $data[$name] ??= $date->setTimezone(new \DateTimeZone($offset))->format('Y-m-d\TH:i');
                // DateTimeType also accepts timezone suffixes and relative text. These inputs must be local minutes.
                if (is_string($data[$name]) && !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $data[$name])) {
                    $event->getForm()->addError(new FormError('Enter start and end as local dates and times, without seconds or a timezone.'));
                }
            }
            $event->setData($data);
        });
    }

    private function addDates(FormInterface $form, string $offset): void
    {
        foreach (['start' => 'Start', 'end' => 'End (inclusive minute)'] as $name => $label) {
            $form->add($name, DateTimeType::class, [
                'label' => $label,
                'widget' => 'single_text',
                'model_timezone' => 'UTC',
                'view_timezone' => $offset,
                'constraints' => [new Assert\NotNull()],
            ]);
        }
    }

    private function today(string $offset): array
    {
        $today = new \DateTime('today', new \DateTimeZone($offset));

        return [
            'start' => (clone $today)->setTimezone(new \DateTimeZone('UTC')),
            'end' => $today->setTime(23, 59)->setTimezone(new \DateTimeZone('UTC')),
        ];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method' => 'GET',
            'csrf_protection' => false,
            'constraints' => [new Assert\Callback(static function (array $data, ExecutionContextInterface $context): void {
                if (($data['tz_hours'] ?? null) === 14 && ($data['tz_minutes'] ?? 0) !== 0) {
                    $context->buildViolation('The UTC offset cannot exceed 14 hours.')->atPath('[tz_minutes]')->addViolation();
                }
                if (($data['start'] ?? null) instanceof \DateTime && ($data['end'] ?? null) instanceof \DateTime
                    && $data['start'] > $data['end']) {
                    $context->buildViolation('End must be on or after start.')->atPath('[end]')->addViolation();
                }
            })],
        ]);
    }
}
