<?php

namespace App\Entity;

use App\Repository\RawRecordRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RawRecordRepository::class)]
class RawRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'rawRecords')]
    #[ORM\JoinColumn(nullable: false)]
    private ?LocationSource $source = null;

    #[ORM\Column(length: 64)]
    private ?string $record_type = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $source_record_dedup_key = null;

    #[ORM\Column(type: Types::BINARY, length: 64)]
    private ?string $payload_hash = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $payload = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $recorded_at = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private ?\DateTimeImmutable $created_at = null;

    /**
     * @var Collection<int, LocationPoint>
     */
    #[ORM\OneToMany(targetEntity: LocationPoint::class, mappedBy: 'raw_record', orphanRemoval: true)]
    private Collection $locationPoints;

    #[ORM\Column(length: 16)]
    private ?string $parse_status = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $parse_error = null;

    #[ORM\Column(type: Types::DATETIMETZ_MUTABLE, nullable: true)]
    private ?\DateTime $parsed_at = null;

    public function __construct()
    {
        $this->locationPoints = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSource(): ?LocationSource
    {
        return $this->source;
    }

    public function setSource(?LocationSource $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getRecordType(): ?string
    {
        return $this->record_type;
    }

    public function setRecordType(string $record_type): static
    {
        $this->record_type = $record_type;

        return $this;
    }

    public function getSourceRecordDedupKey(): ?string
    {
        return $this->source_record_dedup_key;
    }

    public function setSourceRecordDedupKey(?string $source_record_dedup_key): static
    {
        $this->source_record_dedup_key = $source_record_dedup_key;

        return $this;
    }

    public function getPayloadHash(): ?string
    {
        return $this->payload_hash;
    }

    public function setPayloadHash(string $payload_hash): static
    {
        $this->payload_hash = $payload_hash;

        return $this;
    }

    public function getPayload(): ?string
    {
        return $this->payload;
    }

    public function setPayload(string $payload): static
    {
        $this->payload = $payload;

        return $this;
    }

    public function getRecordedAt(): ?\DateTimeImmutable
    {
        return $this->recorded_at;
    }

    public function setRecordedAt(?\DateTimeImmutable $recorded_at): static
    {
        $this->recorded_at = $recorded_at;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->created_at;
    }

    public function setCreatedAt(\DateTimeImmutable $created_at): static
    {
        $this->created_at = $created_at;

        return $this;
    }

    /**
     * @return Collection<int, LocationPoint>
     */
    public function getLocationPoints(): Collection
    {
        return $this->locationPoints;
    }

    public function addLocationPoint(LocationPoint $locationPoint): static
    {
        if (!$this->locationPoints->contains($locationPoint)) {
            $this->locationPoints->add($locationPoint);
            $locationPoint->setRawRecord($this);
        }

        return $this;
    }

    public function removeLocationPoint(LocationPoint $locationPoint): static
    {
        if ($this->locationPoints->removeElement($locationPoint)) {
            // set the owning side to null (unless already changed)
            if ($locationPoint->getRawRecord() === $this) {
                $locationPoint->setRawRecord(null);
            }
        }

        return $this;
    }

    public function getParseStatus(): ?string
    {
        return $this->parse_status;
    }

    public function setParseStatus(string $parse_status): static
    {
        $this->parse_status = $parse_status;

        return $this;
    }

    public function getParseError(): ?string
    {
        return $this->parse_error;
    }

    public function setParseError(?string $parse_error): static
    {
        $this->parse_error = $parse_error;

        return $this;
    }

    public function getParsedAt(): ?\DateTime
    {
        return $this->parsed_at;
    }

    public function setParsedAt(?\DateTime $parsed_at): static
    {
        $this->parsed_at = $parsed_at;

        return $this;
    }
}
