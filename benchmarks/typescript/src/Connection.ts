import { PrismaClient } from '@prisma/client';
import postgres from 'postgres';
import { drizzle } from 'drizzle-orm/postgres-js';

/**
 * Shared database connection instances.
 * Each client is initialized once and reused across benchmark iterations.
 */

// Prisma client — schema-first ORM with built-in dataloader
let prismaInstance: PrismaClient | null = null;

/**
 * Builds DATABASE_URL from individual environment variables.
 * Allows all services to use the same .env file format.
 */
function buildDatabaseUrl(): string {
  const host = process.env.DB_HOST || 'localhost';
  const port = process.env.DB_PORT || '5432';
  const name = process.env.DB_NAME || 'benchmark';
  const user = process.env.DB_USER || 'benchmark';
  const pass = process.env.DB_PASS || 'benchmark';
  return `postgresql://${user}:${pass}@${host}:${port}/${name}`;
}

export function getPrismaClient(): PrismaClient {
  if (!prismaInstance) {
    prismaInstance = new PrismaClient({
      datasources: {
        db: { url: buildDatabaseUrl() },
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
    postgresInstance = postgres(buildDatabaseUrl(), {
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
    rawSqlInstance = postgres(buildDatabaseUrl(), {
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
