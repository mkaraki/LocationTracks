<?php

namespace App\Controller;

use App\Entity\LocationSource;
use App\Entity\RawRecord;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class RawRecordController extends AbstractController
{
    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    #[Route(path: '/api/v1/submit/owntracks', name: 'ingest_api_owntracks', methods: ['POST'])]
    #[IsGranted('ROLE_SUBMITTER')]
    public function ingest_owntracks(
        Request $request,
        #[CurrentUser] LocationSource $source,
        EntityManagerInterface $em,
    ): Response {
        try {
            $data = json_decode(
                $request->getContent(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            return $this->json(
                ['error' => 'Invalid JSON'],
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!is_array($data) || array_is_list($data)) {
            return $this->json(
                ['error' => 'Expected JSON object'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $type = $data['_type'] ?? null;
        if (!is_string($type) || $type === '') {
            return $this->json(
                ['error' => 'Missing _type'],
                Response::HTTP_BAD_REQUEST
            );
        }

        try {
            $normalizedJson = json_encode(
                $this->canonicalize($data),
                JSON_THROW_ON_ERROR |
                JSON_UNESCAPED_SLASHES |
                JSON_UNESCAPED_UNICODE |
                JSON_PRESERVE_ZERO_FRACTION
            );
        } catch (\JsonException $e) {
            return $this->json(
                ['error' => 'Failed to process'],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        $rawRecordRepo = $em->getRepository(RawRecord::class);
        $payloadHash = hash('sha256', $normalizedJson);

        $rawRecord = $rawRecordRepo->findOneBy(['payload_hash' => $payloadHash]);
        if ($rawRecord !== null) {
            return $this->json(
                ['error' => 'Record already exists'],
                Response::HTTP_OK,
            );
        }

        $rawRecord = new RawRecord();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $rawRecord->setCreatedAt($now);
        $rawRecord->setSource($source);
        $rawRecord->setPayloadHash($payloadHash);
        $rawRecord->setPayload($normalizedJson);
        $rawRecord->setCreatedAt(new \DateTimeImmutable());
        $rawRecord->setRecordType('owntracks_' . $type);

        if (isset($data['tst']) && filter_var(
                $data['tst'],
                FILTER_VALIDATE_INT
            ) !== false) {
            $recordedAt = new \DateTimeImmutable(
                '@' . (string) $data['tst']
            )->setTimezone(new \DateTimeZone('UTC'));
            $rawRecord->setRecordedAt($recordedAt);
        }

        // Pre-persist for safety.
        $em->persist($rawRecord);

        if ($type === 'location') {
            $lpc = new LocationPointController();
            $lpc->process_owntracks_location_data(
                $em,
                $source,
                $rawRecord,
                $data,
                false,
            );
        }

        $em->flush();
        return $this->json(
            [],
            Response::HTTP_OK,
        );
    }
}
