import { PrismaClient } from '@prisma/client';
import postgres from 'postgres';
import { drizzle } from 'drizzle-orm/postgres-js';

/**
 * Shared database connection instances.
 * Each client is initialized once and reused across benchmark iterations.
 */

// Prisma client — schema-first ORM with built-in dataloader
let prismaInstance: PrismaClient | null = null;

export function getPrismaClient(): PrismaClient {
  if (!prismaInstance) {
    prismaInstance = new PrismaClient({
      datasources: {
        db: { url: process.env.DATABASE_URL },
      },
    });
  }
  return prismaInstance;
}

// Drizzle client — query-builder over postgres.js
let drizzleInstance: ReturnType<typeof drizzle> | null = null;
let postgresInstance: ReturnType<typeof postgres> | null = null;

export function getDrizzleClient() {
  if (!drizzleInstance) {
    postgresInstance = postgres(process.env.DATABASE_URL!, {
      max: 10, // connection pool size
    });
    drizzleInstance = drizzle(postgresInstance);
  }
  return drizzleInstance;
}

// Raw SQL client — direct postgres.js connection
let rawSqlInstance: ReturnType<typeof postgres> | null = null;

export function getRawSqlClient() {
  if (!rawSqlInstance) {
    rawSqlInstance = postgres(process.env.DATABASE_URL!, {
      max: 10, // connection pool size
    });
  }
  return rawSqlInstance;
}

// Cleanup function for graceful shutdown
export async function closeConnections(): Promise<void> {
  if (prismaInstance) {
    await prismaInstance.$disconnect();
    prismaInstance = null;
  }
  if (postgresInstance) {
    await postgresInstance.end();
    postgresInstance = null;
  }
  if (rawSqlInstance) {
    await rawSqlInstance.end();
    rawSqlInstance = null;
  }
}
