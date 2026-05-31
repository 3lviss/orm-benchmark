FROM node:20-alpine
WORKDIR /app

# Install OpenSSL for Prisma engine compatibility
RUN apk add --no-cache openssl openssl-dev libc6-compat

# Copy package files for layer caching
COPY package.json package-lock.json* ./
RUN npm install

# Copy Prisma schema and generate client
COPY src/scenarios/prisma/schema.prisma src/scenarios/prisma/schema.prisma
RUN npx prisma generate

# Copy source and compile TypeScript
COPY tsconfig.json ./
COPY src/ src/
RUN npm run build

CMD ["node", "dist/run.js"]
