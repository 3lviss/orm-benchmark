import { pgTable, serial, varchar, text, decimal, integer, timestamp, primaryKey } from 'drizzle-orm/pg-core';

export const users = pgTable('users', {
  id:         serial('id').primaryKey(),
  email:      varchar('email', { length: 255 }).notNull().unique(),
  name:       varchar('name', { length: 255 }).notNull(),
  created_at: timestamp('created_at').notNull(),
});

export const categories = pgTable('categories', {
  id:        serial('id').primaryKey(),
  name:      varchar('name', { length: 255 }).notNull(),
  parent_id: integer('parent_id'),
});

export const tags = pgTable('tags', {
  id:   serial('id').primaryKey(),
  name: varchar('name', { length: 255 }).notNull().unique(),
});

export const products = pgTable('products', {
  id:          serial('id').primaryKey(),
  name:        varchar('name', { length: 255 }).notNull(),
  price:       decimal('price', { precision: 10, scale: 2 }).notNull(),
  description: text('description'),
  category_id: integer('category_id').notNull(),
  created_at:  timestamp('created_at').notNull(),
});

export const productTags = pgTable('product_tags', {
  product_id: integer('product_id').notNull(),
  tag_id:     integer('tag_id').notNull(),
}, (t) => ({
  pk: primaryKey({ columns: [t.product_id, t.tag_id] }),
}));

export const orders = pgTable('orders', {
  id:         serial('id').primaryKey(),
  user_id:    integer('user_id').notNull(),
  total:      decimal('total', { precision: 10, scale: 2 }).notNull(),
  status:     varchar('status', { length: 50 }).notNull(),
  created_at: timestamp('created_at').notNull(),
});

export const orderItems = pgTable('order_items', {
  id:         serial('id').primaryKey(),
  order_id:   integer('order_id').notNull(),
  product_id: integer('product_id').notNull(),
  quantity:   integer('quantity').notNull(),
  price:      decimal('price', { precision: 10, scale: 2 }).notNull(),
});

export const reviews = pgTable('reviews', {
  id:         serial('id').primaryKey(),
  user_id:    integer('user_id').notNull(),
  product_id: integer('product_id').notNull(),
  rating:     integer('rating').notNull(),
  comment:    text('comment'),
  created_at: timestamp('created_at').notNull(),
});
