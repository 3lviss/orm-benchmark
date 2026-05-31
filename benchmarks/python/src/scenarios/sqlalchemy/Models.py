from sqlalchemy import (
    Column, Integer, String, Numeric, Text, DateTime, ForeignKey, Table
)
from sqlalchemy.orm import DeclarativeBase, relationship

"""
SQLAlchemy ORM model definitions.
All models share a single Base and are defined here to avoid
circular import issues between model files.
"""

class Base(DeclarativeBase):
    pass

# Many-to-many association table for Product <-> Tag
product_tags = Table(
    'product_tags', Base.metadata,
    Column('product_id', Integer, ForeignKey('products.id'), primary_key=True),
    Column('tag_id',     Integer, ForeignKey('tags.id'),     primary_key=True),
)

class User(Base):
    __tablename__ = 'users'
    id         = Column(Integer, primary_key=True)
    email      = Column(String(255), nullable=False, unique=True)
    name       = Column(String(255), nullable=False)
    created_at = Column(DateTime, nullable=False)
    orders     = relationship('Order',  back_populates='user')
    reviews    = relationship('Review', back_populates='user')

class Category(Base):
    __tablename__ = 'categories'
    id        = Column(Integer, primary_key=True)
    name      = Column(String(255), nullable=False)
    parent_id = Column(Integer, ForeignKey('categories.id'), nullable=True)
    parent    = relationship('Category', remote_side='Category.id',
                             back_populates='children')
    children  = relationship('Category', back_populates='parent')
    products  = relationship('Product',  back_populates='category')

class Tag(Base):
    __tablename__ = 'tags'
    id       = Column(Integer, primary_key=True)
    name     = Column(String(255), nullable=False, unique=True)
    products = relationship('Product', secondary=product_tags,
                            back_populates='tags')

class Product(Base):
    __tablename__ = 'products'
    id          = Column(Integer, primary_key=True)
    name        = Column(String(255), nullable=False)
    price       = Column(Numeric(10, 2), nullable=False)
    description = Column(Text, nullable=True)
    category_id = Column(Integer, ForeignKey('categories.id'), nullable=False)
    created_at  = Column(DateTime, nullable=False)
    category    = relationship('Category',  back_populates='products')
    tags        = relationship('Tag',       secondary=product_tags,
                               back_populates='products')
    order_items = relationship('OrderItem', back_populates='product')
    reviews     = relationship('Review',    back_populates='product')

class Order(Base):
    __tablename__ = 'orders'
    id         = Column(Integer, primary_key=True)
    user_id    = Column(Integer, ForeignKey('users.id'), nullable=False)
    total      = Column(Numeric(10, 2), nullable=False)
    status     = Column(String(50), nullable=False)
    created_at = Column(DateTime, nullable=False)
    user       = relationship('User',      back_populates='orders')
    items      = relationship('OrderItem', back_populates='order',
                              cascade='all, delete-orphan')

class OrderItem(Base):
    __tablename__ = 'order_items'
    id         = Column(Integer, primary_key=True)
    order_id   = Column(Integer, ForeignKey('orders.id'),   nullable=False)
    product_id = Column(Integer, ForeignKey('products.id'), nullable=False)
    quantity   = Column(Integer, nullable=False)
    price      = Column(Numeric(10, 2), nullable=False)
    order      = relationship('Order',   back_populates='items')
    product    = relationship('Product', back_populates='order_items')

class Review(Base):
    __tablename__ = 'reviews'
    id         = Column(Integer, primary_key=True)
    user_id    = Column(Integer, ForeignKey('users.id'),    nullable=False)
    product_id = Column(Integer, ForeignKey('products.id'), nullable=False)
    rating     = Column(Integer, nullable=False)
    comment    = Column(Text, nullable=True)
    created_at = Column(DateTime, nullable=False)
    user       = relationship('User',    back_populates='reviews')
    product    = relationship('Product', back_populates='reviews')
