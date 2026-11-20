<?php

namespace App\Import;

use JsonMachine\Exception\JsonMachineException;
use JsonMachine\Parser;
use JsonMachine\RecursiveItems;
use JsonMachine\StreamChunks;
use JsonMachine\Tokens;

final class GoogleTimelineReader
{
    public const RECORD_POSITION = 'google_timeline_position';

    /**
     * @return iterable<array{string, array, list<array{mixed, mixed}>}>
     */
    public function records(string $filename): iterable
    {
        $stream = fopen($filename, 'rb');
        if (false === $stream) {
            throw new InvalidTimeline('The uploaded file could not be read.');
        }

        try {
            $tokens = new Tokens(new StreamChunks($stream));
            $document = new RecursiveItems(new Parser($tokens, recursive: true));
            $sections = [];
            foreach ($document as $section => $entries) {
                if (!\in_array($section, ['rawSignals', 'semanticSegments'], true)) {
                    continue;
                }
                if (isset($sections[$section]) || !$entries instanceof RecursiveItems) {
                    throw new InvalidTimeline('The Timeline JSON has an invalid section.');
                }
                $sections[$section] = true;

                foreach ($entries as $index => $entry) {
                    if (!\is_int($index) || !$entry instanceof RecursiveItems) {
                        throw new InvalidTimeline('The Timeline JSON has an invalid record.');
                    }

                    if ('semanticSegments' === $section) {
                        yield from $this->segmentRecords($this->readRecord($entry));
                    } else {
                        yield from $this->signalRecords($entry);
                    }
                }
            }

            // The parser stops on the root's closing token, so check for trailing tokens.
            $remaining = $tokens->getIterator();
            $remaining->next();
            if ($remaining->valid()) {
                throw new InvalidTimeline('The uploaded file is not valid Timeline JSON.');
            }

            if (!$sections) {
                throw new InvalidTimeline('Select a Timeline JSON file exported from Google Maps on Android.');
            }
        } catch (JsonMachineException) {
            throw new InvalidTimeline('The uploaded file is not valid Timeline JSON.');
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return iterable<array{string, array, list<array{mixed, mixed}>}>
     */
    private function signalRecords(RecursiveItems $entry): iterable
    {
        foreach ($entry as $key => $value) {
            if ('position' !== $key) {
                continue;
            }
            if (!$value instanceof RecursiveItems) {
                throw new InvalidTimeline('The Timeline JSON has an invalid position.');
            }
            $position = $this->readRecord($value);

            yield [self::RECORD_POSITION, $position, [
                [$position['LatLng'] ?? $position['latLng'] ?? null, $position['timestamp'] ?? null],
            ]];
        }
    }

    private function readRecord(RecursiveItems $items, int $depth = 0): array
    {
        if ($depth >= 32) {
            throw new InvalidTimeline('The Timeline JSON contains a record with too many nested fields.');
        }

        $record = [];
        foreach ($items as $key => $value) {
            $record[$key] = $value instanceof RecursiveItems ? $this->readRecord($value, $depth + 1) : $value;
        }

        return $record;
    }

    /**
     * @return iterable<array{string, array, list<array{mixed, mixed}>}>
     */
    private function segmentRecords(array $segment): iterable
    {
        if (\is_array($segment['timelinePath'] ?? null)) {
            foreach ($segment['timelinePath'] as $point) {
                if (\is_array($point)) {
                    yield ['google_timeline_path', $point, [[$point['point'] ?? null, $point['time'] ?? null]]];
                }
            }
        }

        if (\is_array($segment['visit'] ?? null)) {
            yield ['google_timeline_visit', $segment, [
                [$segment['visit']['topCandidate']['placeLocation']['latLng'] ?? null, $segment['startTime'] ?? null],
            ]];
        }

        if (\is_array($segment['activity'] ?? null)) {
            $activity = $segment['activity'];

            yield ['google_timeline_activity', $segment, [
                [$activity['start']['latLng'] ?? null, $segment['startTime'] ?? null],
                [$activity['end']['latLng'] ?? null, $segment['endTime'] ?? null],
                [$activity['parking']['location']['latLng'] ?? null, $activity['parking']['startTime'] ?? null],
            ]];
        }
    }
}
