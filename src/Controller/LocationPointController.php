<?php

namespace App\Controller;

use App\Entity\LocationPoint;
use App\Entity\LocationSource;
use App\Entity\RawRecord;
use App\Entity\User;
use App\Form\PointMapFilterType;
use App\Repository\LocationPointRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Map\Icon\Icon;
use Symfony\UX\Map\Map;
use Symfony\UX\Map\Marker;
use Symfony\UX\Map\Point;
use Symfony\UX\Map\Polyline;
use Symfony\UX\Pagination\PaginatorInterface;

class LocationPointController extends AbstractController
{
    #[Route('/points/', name: 'point_index')]
    #[IsGranted('ROLE_USER')]
    public function index(
        LocationPointRepository $repository,
        PaginatorInterface $paginator,
        #[CurrentUser] User $user,
    ): Response
    {
        $items = $paginator
            ->cursor(
                $repository->createQueryBuilder('points')
                    ->innerJoin('points.source', 'sources')
                    ->where('sources.user = :user')
                    ->setParameter('user', $user)
            )
            ->orderBy('id', 'DESC')
            ->perPage(100)
            ->paginate();

        return $this->render('points/index.html.twig', [
            'items' => $items,
        ]);
    }

    #[Route('/points/map', name: 'point_map')]
    #[IsGranted('ROLE_USER')]
    public function map(
        LocationPointRepository $repository,
        #[CurrentUser] User $user,
        Request $request,
        FormFactoryInterface $formFactory,
    ): Response
    {
        $form = $formFactory->createNamed('', PointMapFilterType::class, options: [
            'action' => $this->generateUrl('point_map'),
        ]);
        $form->handleRequest($request);
        $valid = !$form->isSubmitted() || $form->isValid();
        $filter = $form->getData();
        $items = $valid ? $repository->findByUserAndTimeRange(
            $user,
            $filter['start'],
            (clone $filter['end'])->modify('+59 seconds'),
        ) : [];
        $itemsCount = count($items);

        $map = new Map();

        if ($itemsCount === 0) {
            $marker = new Marker(
                position: new Point(0, 0),
                title: 'No data found',
            );
            $map = $map
                ->center(new Point(0, 0))
                ->addMarker($marker)
                ->zoom(6);
        } else if ($itemsCount === 1) {
            $item = $items[0];
            $marker = new Marker(
                position: new Point($item->getLatitude(), $item->getLongitude()),
                title: 'Id: #' . $item->getId(),
            );
            $map = $map
                ->center(new Point($item->getLatitude(), $item->getLongitude()))
                ->zoom(17)
                ->addMarker($marker);
        } else {
            $points = [];
            foreach ($items as $item) {
                $points[] = new Point($item->getLatitude(), $item->getLongitude());
            }
            $polyLine = new Polyline(
                points: $points,
            );

            $icon = Icon::svg('<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"><circle r="2" cx="12" cy="12" fill="red" /></svg>');

            $markers = array_map(function (Point $array) use ($icon): Marker { return new Marker(
                position: $array,
                icon: $icon,
            ); }, $points);

            $map = $map
                ->addPolyline($polyLine);

            foreach ($markers as $marker) {
                $map = $map->addMarker($marker);
            }

            $map = $map
                ->fitBoundsToMarkers();
        }

        return $this->render('points/map.html.twig', [
            'map' => $map,
            'itemsCount' => $itemsCount,
            'filterForm' => $form,
        ], new Response(status: $valid ? 200 : 422));
    }

    public function process_owntracks_location_data(
        EntityManagerInterface $em,
        LocationSource $source,
        RawRecord $rawRecord,
        array $data,
        bool $flush = true,
    ): ?LocationPoint {
        $rawRecord->setParsedAt(new \DateTime('now'));
        $rawRecord->setParseStatus('parsing');
        $em->persist($rawRecord);

        if (
            !isset($data['tst']) || !is_numeric($data['tst']) ||
            !isset($data['lat']) || !is_numeric($data['lat']) ||
            !isset($data['lon']) || !is_numeric($data['lon'])
        ) {
            // Unable to create.
            $rawRecord->setParseStatus('error');
            $rawRecord->setParseError('Numeric records: `tst`, `lat`, and `lon` are required.');
            $em->persist($rawRecord);
            if ($flush) {
                $em->flush();
            }
            return null;
        }

        $timestamp = filter_var(
            $data['tst'],
            FILTER_VALIDATE_INT
        );

        $latitude = (float) $data['lat'];
        $longitude = (float) $data['lon'];

        if (
            $timestamp === false ||
            !is_finite($latitude) ||
            !is_finite($longitude) ||
            $latitude < -90 ||
            $latitude > 90 ||
            $longitude < -180 ||
            $longitude > 180
        ) {
            // Unable to create
            $rawRecord->setParseStatus('error');
            $rawRecord->setParseError('Records: `tst`, `lat`, and `lon` are invalid.');
            $em->persist($rawRecord);
            if ($flush) {
                $em->flush();
            }
            return null;
        }

        $point = new LocationPoint();
        $point->setSource($source);
        $point->setRawRecord($rawRecord);
        $recordedAt = new \DateTime(
            '@' . (string) $data['tst']
        )->setTimezone(new \DateTimeZone('UTC'));
        $point->setRecordedAt($recordedAt);
        $point->setLatitude($latitude);
        $point->setLongitude($longitude);
        $point->setCreatedAt(new \DateTimeImmutable('now'));

        if (isset($data['acc']) && is_numeric($data['acc'])) {
            $point->setAccuracyM((float) $data['acc']);
        }

        if (isset($data['alt']) && is_numeric($data['alt'])) {
            $point->setAltitudeM((float) $data['alt']);
        }

        if (isset($data['vel']) && is_numeric($data['vel'])) {
            // OwnTracks の vel は km/h
            $point->setSpeedKmh((float) $data['vel']);
        }

        if (isset($data['cog']) && is_numeric($data['cog'])) {
            $point->setHeadingDeg((float) $data['cog']);
        }

        if (isset($data['batt']) && is_numeric($data['batt'])) {
            $point->setBatteryPercent((int) $data['batt']);
        }

        $metadata = array_diff_key(
            $data,
            array_flip([
                '_type', 'tst', 'lat', 'lon',
                'acc', 'alt', 'vel', 'cog', 'batt',
            ])
        );
        $point->setMetadata($metadata);

        $em->persist($point);

        $rawRecord->setParsedAt(new \DateTime('now'));
        $rawRecord->setParseStatus('done');
        $em->persist($rawRecord);

        if ($flush) {
            $em->flush();
        }

        return $point;
    }
}
