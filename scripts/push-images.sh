#!/usr/bin/env bash
set -euo pipefail

# Usage:
#   DOCKER_USER=<your_user> TAG=<tag> ./scripts/push-images.sh
# Defaults match docker-compose.yml in this repo.

: "${DOCKER_USER:=nourddinelearning}"
: "${TAG:=v2025.09.15}"

echo "Pushing images as ${DOCKER_USER} with tag ${TAG}"
echo "Ensure you are logged in: docker login"

# 1) Build and push app image
echo "[1/4] Building app image"
docker build -t ${DOCKER_USER}/gam_quiz:${TAG} .
docker tag ${DOCKER_USER}/gam_quiz:${TAG} ${DOCKER_USER}/gam_quiz:latest

echo "[2/4] Pushing app image tags"
docker push ${DOCKER_USER}/gam_quiz:${TAG}
docker push ${DOCKER_USER}/gam_quiz:latest

# 2) Retag and push MariaDB image
echo "[3/4] Pulling base MariaDB and retagging"
docker pull docker.io/bitnami/mariadb:10.6
docker tag docker.io/bitnami/mariadb:10.6 ${DOCKER_USER}/mariadb-gam_quiz:${TAG}
docker tag docker.io/bitnami/mariadb:10.6 ${DOCKER_USER}/mariadb-gam_quiz:latest

echo "[4/4] Pushing DB image tags"
docker push ${DOCKER_USER}/mariadb-gam_quiz:${TAG}
docker push ${DOCKER_USER}/mariadb-gam_quiz:latest

echo "Done. Update docker-compose.yml images if you changed DOCKER_USER or TAG."

