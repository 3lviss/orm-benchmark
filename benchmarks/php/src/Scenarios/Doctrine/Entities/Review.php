<?php

namespace Benchmark\Scenarios\Doctrine\Entities;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'reviews')]
class Review
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    private int $id;

    #[ORM\Column(type: 'integer')]
    private int $rating;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(name: 'created_at', type: 'datetime')]
    private \DateTime $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'reviews')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'reviews')]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id')]
    private Product $product;

    public function getId(): int { return $this->id; }
    public function getRating(): int { return $this->rating; }
    public function getComment(): ?string { return $this->comment; }
    public function getCreatedAt(): \DateTime { return $this->createdAt; }
    public function getUser(): User { return $this->user; }
    public function getProduct(): Product { return $this->product; }
    public function setUser(User $user): void { $this->user = $user; }
    public function setProduct(Product $product): void { $this->product = $product; }
    public function setRating(int $rating): void { $this->rating = $rating; }
    public function setComment(?string $comment): void { $this->comment = $comment; }
    public function setCreatedAt(\DateTime $dt): void { $this->createdAt = $dt; }
}
