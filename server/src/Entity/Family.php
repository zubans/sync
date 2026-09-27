<?php

namespace App\Entity;

use App\Repository\FamilyRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** Семейная группа: общие контакты доступны всем её участникам. */
#[ORM\Entity(repositoryClass: FamilyRepository::class)]
class Family
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private ?string $name = null;

    /** @var Collection<int, User> */
    #[ORM\OneToMany(targetEntity: User::class, mappedBy: 'family')]
    #[ORM\OrderBy(['email' => 'ASC'])]
    private Collection $members;

    /** @var Collection<int, Contact> */
    #[ORM\OneToMany(targetEntity: Contact::class, mappedBy: 'family')]
    private Collection $contacts;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->members = new ArrayCollection();
        $this->contacts = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /** @return Collection<int, User> */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    // Владеющая сторона связи — User::$family, поэтому add/remove выставляют её.
    public function addMember(User $user): static
    {
        if (!$this->members->contains($user)) {
            $this->members->add($user);
            $user->setFamily($this);
        }

        return $this;
    }

    public function removeMember(User $user): static
    {
        if ($this->members->removeElement($user) && $user->getFamily() === $this) {
            $user->setFamily(null);
        }

        return $this;
    }

    /** @return Collection<int, Contact> */
    public function getContacts(): Collection
    {
        return $this->contacts;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
