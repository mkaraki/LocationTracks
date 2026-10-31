<?php

namespace App\Controller;

use App\Entity\LocationPoint;
use App\Entity\LocationSource;
use App\Entity\RawRecord;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class LocationPointController extends AbstractController
{
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
