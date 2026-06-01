package com.benchmark.scenarios.hibernate.entities;

import jakarta.persistence.*;
import java.util.List;

@Entity
@Table(name = "tags")
public class Tag {

    @Id
    @GeneratedValue(strategy = GenerationType.IDENTITY)
    private int id;

    @Column(nullable = false, unique = true)
    private String name;

    @ManyToMany(mappedBy = "tags", fetch = FetchType.LAZY)
    private List<Product> products;

    public int getId() { return id; }
    public String getName() { return name; }
    public List<Product> getProducts() { return products; }
}
