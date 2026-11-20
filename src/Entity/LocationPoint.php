<?php

namespace App\Entity;

use App\Repository\LocationPointRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LocationPointRepository::class)]
#[ORM\Index(name: 'idx_location_point_source_recorded_at', columns: ['source_id', 'recorded_at'])]
class LocationPoint
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'locationPoints')]
    #[ORM\JoinColumn(nullable: false)]
    private ?LocationSource $source = null;

    #[ORM\ManyToOne(inversedBy: 'locationPoints')]
    #[ORM\JoinColumn(nullable: false)]
    private ?RawRecord $raw_record = null;

    #[ORM\OneToOne(targetEntity: self::class, inversedBy: 'supersededBy', cascade: ['persist', 'remove'])]
    private ?self $supersedes = null;

    #[ORM\OneToOne(targetEntity: self::class, mappedBy: 'supersedes', cascade: ['persist', 'remove'])]
    private ?self $supersededBy = null;

    #[ORM\Column(type: Types::DATETIMETZ_MUTABLE)]
    private ?\DateTime $recorded_at = null;

    #[ORM\Column]
    private ?float $latitude = null;

    #[ORM\Column]
    private ?float $longitude = null;

    #[ORM\Column(nullable: true)]
    private ?float $accuracy_m = null;

    #[ORM\Column(nullable: true)]
    private ?float $altitude_m = null;

    #[ORM\Column(nullable: true)]
    private ?float $speed_kmh = null;

    #[ORM\Column(nullable: true)]
    private ?float $heading_deg = null;

    #[ORM\Column(nullable: true)]
    private ?int $battery_percent = null;

    #[ORM\Column(nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private ?\DateTimeImmutable $created_at = null;

    #[ORM\Column(type: Types::DATETIMETZ_MUTABLE, nullable: true)]
    private ?\DateTime $deleted_at = null;

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

    public function getRawRecord(): ?RawRecord
    {
        return $this->raw_record;
    }

    public function setRawRecord(?RawRecord $raw_record): static
    {
        $this->raw_record = $raw_record;

        return $this;
    }

    public function getSupersedes(): ?self
    {
        return $this->supersedes;
    }

    public function setSupersedes(?self $supersedes): static
    {
        $this->supersedes = $supersedes;

        return $this;
    }

    public function getSupersededBy(): ?self
    {
        return $this->supersededBy;
    }

    public function setSupersededBy(?self $supersededBy): static
    {
        // unset the owning side of the relation if necessary
        if ($supersededBy === null && $this->supersededBy !== null) {
            $this->supersededBy->setSupersedes(null);
        }

        // set the owning side of the relation if necessary
        if ($supersededBy !== null && $supersededBy->getSupersedes() !== $this) {
            $supersededBy->setSupersedes($this);
        }

        $this->supersededBy = $supersededBy;

        return $this;
    }

    public function getRecordedAt(): ?\DateTime
    {
        return $this->recorded_at;
    }

    public function setRecordedAt(\DateTime $recorded_at): static
    {
        $this->recorded_at = $recorded_at;

        return $this;
    }

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function setLatitude(float $latitude): static
    {
        $this->latitude = $latitude;

        return $this;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function setLongitude(float $longitude): static
    {
        $this->longitude = $longitude;

        return $this;
    }

    public function getAccuracyM(): ?float
    {
        return $this->accuracy_m;
    }

    public function setAccuracyM(?float $accuracy_m): static
    {
        $this->accuracy_m = $accuracy_m;

        return $this;
    }

    public function getAltitudeM(): ?float
    {
        return $this->altitude_m;
    }

    public function setAltitudeM(?float $altitude_m): static
    {
        $this->altitude_m = $altitude_m;

        return $this;
    }

    public function getSpeedKmh(): ?float
    {
        return $this->speed_kmh;
    }

    public function setSpeedKmh(?float $speed_kmh): static
    {
        $this->speed_kmh = $speed_kmh;

        return $this;
    }

    public function getHeadingDeg(): ?float
    {
        return $this->heading_deg;
    }

    public function setHeadingDeg(?float $heading_deg): static
    {
        $this->heading_deg = $heading_deg;

        return $this;
    }

    public function getBatteryPercent(): ?int
    {
        return $this->battery_percent;
    }

    public function setBatteryPercent(?int $battery_percent): static
    {
        $this->battery_percent = $battery_percent;

        return $this;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): static
    {
        $this->metadata = $metadata;

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

    public function getDeletedAt(): ?\DateTime
    {
        return $this->deleted_at;
    }

    public function setDeletedAt(?\DateTime $deleted_at): static
    {
        $this->deleted_at = $deleted_at;

        return $this;
    }
}
