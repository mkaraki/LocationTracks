<?php

namespace App\Controller;

use App\Entity\LocationSource;
use App\Entity\User;
use App\Form\LocationSourceType;
use App\Import\GoogleTimelineImporter;
use App\Import\GoogleTimelineSourceRemover;
use App\Import\InvalidTimeline;
use App\Repository\LocationSourceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Pagination\PaginatorInterface;

class LocationSourceController extends AbstractController
{
    #[Route('/settings/sources/', name: 'source_index')]
    #[IsGranted('ROLE_USER')]
    public function index(
        LocationSourceRepository $repository,
        PaginatorInterface $paginator,
        #[CurrentUser] User $user,
    ): Response
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
            'owntracks_type' => LocationSource::TYPE_OWNTRACKS,
            'timeline_type' => LocationSource::TYPE_GOOGLE_TIMELINE,
        ]);
    }

    #[Route('/settings/sources/new', name: 'source_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(
        EntityManagerInterface $em,
        #[CurrentUser] User $user,
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        GoogleTimelineImporter $importer,
    ): Response {
        $form = $this->createForm(LocationSourceType::class, options: [
            'action' => $this->generateUrl('source_new'),
        ])->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            if (LocationSource::TYPE_GOOGLE_TIMELINE === $data['source_type']) {
                try {
                    $result = $importer->import($user, $data['name'], $data['file']->getPathname());
                    $message = sprintf('Imported %s location %s from Google Timeline.', number_format($result['added']), 1 === $result['added'] ? 'point' : 'points');
                    if ($result['skipped'] > 0) {
                        $message .= sprintf(' Skipped %s duplicate %s.', number_format($result['skipped']), 1 === $result['skipped'] ? 'point' : 'points');
                    }
                    $this->addFlash('timeline_source', $message);

                    return $this->redirectToRoute('source_index', status: Response::HTTP_SEE_OTHER);
                } catch (InvalidTimeline $exception) {
                    $form->get('file')->addError(new FormError($exception->getMessage()));
                }
            } elseif ($em->getRepository(LocationSource::class)->findOneBy(['auth_username' => $data['auth_username']])) {
                $form->get('auth_username')->addError(new FormError('This username is already in use.'));
            } else {
                $source = (new LocationSource())
                    ->setUser($user)
                    ->setSourceType(LocationSource::TYPE_OWNTRACKS)
                    ->setName($data['name'])
                    ->setAuthUsername($data['auth_username'])
                    ->setCreatedAt(new \DateTimeImmutable());
                $source->setAuthPasswordHash($passwordHasher->hashPassword($source, $data['auth_password_hash']));
                $em->persist($source);
                $em->flush();

                return $this->redirectToRoute('source_index');
            }
        }

        return $this->render('settings/sources/new.html.twig', ['form' => $form]);
    }

    #[Route('/settings/sources/{id:source}/remove', name: 'source_timeline_remove', methods: ['GET', 'POST'])]
    #[IsCsrfTokenValid(new Expression('"remove-timeline-" ~ args["source"].getId()'), tokenKey: 'token', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function removeTimeline(
        LocationSource $source,
        #[CurrentUser] User $user,
        Request $request,
        GoogleTimelineSourceRemover $remover,
    ): Response {
        if ($source->getUser()->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException();
        }
        if (LocationSource::TYPE_GOOGLE_TIMELINE !== $source->getSourceType()) {
            throw $this->createNotFoundException();
        }

        $submitted = $request->isMethod('POST');
        if ($submitted && $remover->remove($source)) {
            $this->addFlash('timeline_source', 'Deleted the Google Timeline source and its location history.');

            return $this->redirectToRoute('source_index', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('settings/sources/remove_timeline.html.twig', [
            'source' => $source,
            'busy' => $submitted,
        ], new Response(status: $submitted ? Response::HTTP_CONFLICT : Response::HTTP_OK));
    }

    #[Route('/settings/sources/{id:source}/delete', name: 'source_delete', methods: ['POST'])]
    #[IsCsrfTokenValid('delete-item', tokenKey: 'token')]
    #[IsGranted('ROLE_USER')]
    public function delete(
        EntityManagerInterface $em,
        LocationSource $source,
        #[CurrentUser] User $user,
    ): Response {
        if ($source->getUser()->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException();
        }

        if (LocationSource::TYPE_OWNTRACKS !== $source->getSourceType() || null !== $source->getDeletedAt()) {
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
