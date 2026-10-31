<?php

namespace App\Entity;

use App\Repository\LocationSourceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: LocationSourceRepository::class)]
class LocationSource implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column]
    private ?int $source_type = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $auth_username = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $auth_password_hash = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $owntracks_device_id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $owntracks_tracker_id = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private ?\DateTimeImmutable $created_at = null;

    #[ORM\Column(type: Types::DATETIMETZ_MUTABLE, nullable: true)]
    private ?\DateTime $deleted_at = null;

    /**
     * @var Collection<int, RawRecord>
     */
    #[ORM\OneToMany(targetEntity: RawRecord::class, mappedBy: 'source', orphanRemoval: true)]
    private Collection $rawRecords;

    /**
     * @var Collection<int, LocationPoint>
     */
    #[ORM\OneToMany(targetEntity: LocationPoint::class, mappedBy: 'source', orphanRemoval: true)]
    private Collection $locationPoints;

    public function __construct()
    {
        $this->rawRecords = new ArrayCollection();
        $this->locationPoints = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getSourceType(): ?int
    {
        return $this->source_type;
    }

    public function setSourceType(int $source_type): static
    {
        $this->source_type = $source_type;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getAuthUsername(): ?string
    {
        return $this->auth_username;
    }

    public function setAuthUsername(?string $auth_username): static
    {
        $this->auth_username = $auth_username;

        return $this;
    }

    public function getAuthPasswordHash(): ?string
    {
        return $this->auth_password_hash;
    }

    public function setAuthPasswordHash(?string $auth_password_hash): static
    {
        $this->auth_password_hash = $auth_password_hash;

        return $this;
    }

    public function getOwntracksDeviceId(): ?string
    {
        return $this->owntracks_device_id;
    }

    public function setOwntracksDeviceId(?string $owntracks_device_id): static
    {
        $this->owntracks_device_id = $owntracks_device_id;

        return $this;
    }

    public function getOwntracksTrackerId(): ?string
    {
        return $this->owntracks_tracker_id;
    }

    public function setOwntracksTrackerId(?string $owntracks_tracker_id): static
    {
        $this->owntracks_tracker_id = $owntracks_tracker_id;

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

    /**
     * @return Collection<int, RawRecord>
     */
    public function getRawRecords(): Collection
    {
        return $this->rawRecords;
    }

    public function addRawRecord(RawRecord $rawRecord): static
    {
        if (!$this->rawRecords->contains($rawRecord)) {
            $this->rawRecords->add($rawRecord);
            $rawRecord->setSource($this);
        }

        return $this;
    }

    public function removeRawRecord(RawRecord $rawRecord): static
    {
        if ($this->rawRecords->removeElement($rawRecord)) {
            // set the owning side to null (unless already changed)
            if ($rawRecord->getSource() === $this) {
                $rawRecord->setSource(null);
            }
        }

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
            $locationPoint->setSource($this);
        }

        return $this;
    }

    public function removeLocationPoint(LocationPoint $locationPoint): static
    {
        if ($this->locationPoints->removeElement($locationPoint)) {
            // set the owning side to null (unless already changed)
            if ($locationPoint->getSource() === $this) {
                $locationPoint->setSource(null);
            }
        }

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->auth_username;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        if ($this->deleted_at === null) {
            return ['ROLE_SUBMITTER'];
        } else {
            return [];
        }
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        // DO NOTHING
        // $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->auth_password_hash;
    }

    public function setPassword(string $password): static
    {
        $this->auth_password_hash = $password;

        return $this;
    }
}
