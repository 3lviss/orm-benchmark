FROM maven:3.9-eclipse-temurin-21-alpine AS builder

WORKDIR /build

# Copy pom.xml first for layer caching
COPY pom.xml .
RUN mvn dependency:go-offline -q

# Copy source and build
COPY src/ src/
RUN mvn package -DskipTests -q

# ── Runtime image ─────────────────────────────────────────────────────────────
FROM eclipse-temurin:21-jre-alpine

WORKDIR /app

COPY --from=builder /build/target/orm-benchmark-java-1.0.0-jar-with-dependencies.jar app.jar

CMD ["sh", "-c", "java $JAVA_OPTS -jar app.jar"]
