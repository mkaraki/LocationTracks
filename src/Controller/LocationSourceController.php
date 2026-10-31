<?php

namespace App\Controller;

use App\Entity\LocationSource;
use App\Entity\User;
use App\Repository\LocationSourceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Pagination\PaginatorInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Validator\Constraints as Assert;

class LocationSourceController extends AbstractController
{
    #[Route('/settings/sources/', name: 'source_index')]
    #[IsGranted('ROLE_USER')]
    public function index(
        LocationSourceRepository $repository,
        PaginatorInterface $paginator,
        #[CurrentUser] User $user,
    ): \Symfony\Component\HttpFoundation\Response
    {
        $items = $paginator
            ->cursor(
                $repository->createQueryBuilder('sources')
                ->where('sources.user = :user')
                ->setParameter('user', $user)
            )
            ->orderBy('id', 'DESC')
            ->perPage(100)
            ->paginate();

        return $this->render('settings/sources/index.html.twig', [
            'items' => $items,
        ]);
    }

    #[Route('/settings/sources/new', name: 'source_new')]
    #[IsGranted('ROLE_USER')]
    public function new(
        EntityManagerInterface $em,
        #[CurrentUser] User $user,
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
    ) {
        $entity = new LocationSource();
        $entity->setUser($user);

        $form = $this->createFormBuilder($entity)
            ->add('name', TextType::class, [
                'label' => 'Source name',
                'required' => true,
            ])
            ->add('source_type', ChoiceType::class, [
                'required' => true,
                'label' => 'Type',
                'choices' => [
                    'OwnTracks' => 1,
                ]
            ])
            ->add('auth_username', TextType::class, [
                'required' => false,
                'label' => 'BASIC Auth Username',
                'constraints' => [
                    new Assert\When(
                        expression: 'this.getParent().get("source_type").getData() === 1',
                        constraints: [
                            new Assert\NotBlank(),
                        ]
                    )
                ],
            ])
            ->add('auth_password_hash', PasswordType::class, [
                'required' => false,
                'label' => 'BASIC Auth Password',
                'constraints' => [
                    new Assert\When(
                        expression: 'this.getParent().get("source_type").getData() === 1',
                        constraints: [
                            new Assert\NotBlank(),
                        ]
                    )
                ],
            ])
            ->add('save', SubmitType::class)
            ->getForm();

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $entity->setUser($this->getUser());
            $entity->setCreatedAt(new \DateTimeImmutable('now'));

            $hashedPassword = $passwordHasher->hashPassword(
                $entity,
                $form->get('auth_password_hash')->getData()
            );
            $entity->setPassword($hashedPassword);

            $em->persist($entity);
            $em->flush();

            return $this->redirectToRoute('source_index');
        }

        return $this->render('settings/sources/new.html.twig', [
            'form' => $form,
        ]);

    }

    #[Route('/settings/sources/{id:source}/delete', name: 'source_delete', methods: ['POST'])]
    #[IsCsrfTokenValid('delete-item', tokenKey: 'token')]
    #[IsGranted('ROLE_USER')]
    public function delete(
        EntityManagerInterface $em,
        LocationSource $source,
        #[CurrentUser] User $user,
    )
    {
        if ($source->getUser()->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException();
        }

        if ($source->getDeletedAt() !== null) {
            throw $this->createNotFoundException();
        }

        $source->setDeletedAt(new \DateTime('now'));
        $source->setAuthUsername(null);
        $source->setAuthPasswordHash(null);
        $em->persist($source);
        $em->flush();

        return $this->redirectToRoute('source_index');
    }
}
